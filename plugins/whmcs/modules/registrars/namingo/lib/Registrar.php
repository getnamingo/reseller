<?php

declare(strict_types=1);

namespace Namingo\Whmcs;

use RuntimeException;

final class Registrar
{
    private array $route;
    private string $domain;
    private string $scope;

    public function __construct(private array $params, private ApiClient $api, private Store $store)
    {
        $this->domain = self::domain($params);
        $this->scope = hash('sha256', $api->identity() . '|' . ($params['domainid'] ?? 0) . '|' . $this->domain);
        $registry = self::registry($this->domain, $params);
        $this->route = [];
        foreach ($api->request('GET', '/v1/registries') as $row) {
            if (($row['registry_uuid'] ?? '') === $registry) { $this->route = $row; break; }
        }
        if ($this->route === []) { throw new RuntimeException('The configured registry is not enabled for this reseller.'); }
        // These profiles share the native parameter and response contract used here.
        if (!in_array($this->route['profile'], ['generic', 'UA', 'VRSN'], true)) {
            throw new RuntimeException('This module currently supports generic, UA and VRSN registry profiles; this registry needs a profile adapter.');
        }
    }

    public static function domain(array $params): string
    {
        $sld = !empty($params['sld_punycode']) ? $params['sld_punycode'] : ($params['sld'] ?? '');
        $tld = !empty($params['tld_punycode']) ? $params['tld_punycode'] : ($params['tld'] ?? '');
        $domain = !empty($params['domain_punycode']) ? $params['domain_punycode'] : ($sld . '.' . ltrim((string) $tld, '.'));
        return self::hostname((string) $domain);
    }

    public static function hostname(string $domain): string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if (strlen($domain) > 253 || !str_contains($domain, '.')
            || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain)) {
            throw new RuntimeException('A valid ASCII/punycode domain name is required. Enable WHMCS IDN conversion for international domains.');
        }
        return $domain;
    }

    public static function registry(string $domain, array $config): string
    {
        $map = json_decode(trim((string) ($config['RegistryMap'] ?? '')) ?: '{}', true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($map)) { throw new RuntimeException('Registry map must be a JSON object of TLDs and registry UUIDs.'); }
        $registry = trim((string) ($config['DefaultRegistry'] ?? ''));
        $length = 0;
        foreach ($map as $suffix => $id) {
            $suffix = strtolower(trim((string) $suffix, ". \t\r\n"));
            if ($suffix !== '' && str_ends_with($domain, '.' . $suffix) && strlen($suffix) > $length) {
                $registry = (string) $id;
                $length = strlen($suffix);
            }
        }
        if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD', $registry)) {
            throw new RuntimeException('Configure a registry UUID for this TLD in the Namingo module settings.');
        }
        return $registry;
    }

    public function locked(callable $callback): mixed
    {
        $this->store->lock($this->scope);
        try { return $callback($this); }
        finally { $this->store->unlock($this->scope); }
    }

    private function read(string $command, array $params): array
    {
        return ApiClient::result($this->api->await($this->api->job($this->route['registry_uuid'], $command, $params)));
    }

    /** Save before POST, including the exact payload: timeout retries cannot enqueue a second write. */
    private function write(string $action, string $command, callable $payload, bool $retain = false, bool $pending = false): array
    {
        $key = hash('sha256', $this->scope . '|' . $action);
        $saved = $this->store->get($key);
        $firstSubmission = $saved === null;
        if ($saved === null) {
            $saved = ['idempotency' => 'whmcs-' . bin2hex(random_bytes(24)),
                'registry' => $this->route['registry_uuid'], 'command' => $command, 'params' => $payload()];
            $this->store->put($key, $saved);
        } elseif ($saved['params'] !== $payload()) {
            throw new RuntimeException('This action has a saved request with different input. Retry with the original input until it finishes; reconcile unknown jobs before changing the request.');
        }
        if ($saved['registry'] !== $this->route['registry_uuid'] || $saved['command'] !== $command) {
            throw new RuntimeException('A saved Namingo request uses another registry. Restore the original mapping before retrying.');
        }
        try {
            $job = isset($saved['job'])
                ? $this->api->request('GET', '/v1/jobs/' . rawurlencode($saved['job']))
                : $this->api->job($saved['registry'], $command, $saved['params'], $saved['idempotency']);
        } catch (RuntimeException $error) {
            // These API errors reject a new submission before a job is committed.
            // Never clear state for failed job reads, 409 conflicts, outages or timeouts.
            if ($firstSubmission && in_array($error->getCode(), [400, 402, 403, 404, 405, 422, 428], true)) {
                $this->store->remove($key);
            }
            throw $error;
        }
        if (!empty($job['job_uuid'])) {
            $saved['job'] = $job['job_uuid'];
            $this->store->put($key, $saved);
        }
        $job = $this->api->await($job);
        // Rejected means the API confirmed failure. Corrected input may start a fresh attempt.
        if (($job['status'] ?? '') === 'rejected') { $this->store->remove($key); }
        $result = ApiClient::result($job, $pending);
        if (!$retain && ($job['status'] ?? '') === 'succeeded') { $this->store->remove($key); }
        return $result;
    }

    public function info(): array
    {
        return $this->read('domain.info', ['domainname' => $this->domain]);
    }

    private function years(): int
    {
        $years = filter_var($this->params['regperiod'] ?? null, FILTER_VALIDATE_INT);
        if ($years === false || $years < 1 || $years > 10) { throw new RuntimeException('Registration period must be 1–10 years.'); }
        return $years;
    }

    public function nameservers(): array
    {
        $servers = [];
        for ($i = 1; $i <= 5; ++$i) {
            if (!empty($this->params['ns' . $i])) { $servers['ns' . $i] = self::hostname($this->params['ns' . $i]); }
        }
        if (count($servers) < 2) { throw new RuntimeException('Provide at least two valid nameservers.'); }
        return $servers;
    }

    public function register(): array
    {
        if ((int) ($this->params['domainid'] ?? 0) < 1) { throw new RuntimeException('A WHMCS domain ID is required for registration.'); }
        if ((float) ($this->params['premiumCost'] ?? 0) > 0) { throw new RuntimeException('Premium registrations are not supported by this API module.'); }
        foreach (['dnsmanagement', 'emailforwarding', 'idprotection', 'isPremium'] as $addon) {
            if (!empty($this->params[$addon])) { throw new RuntimeException('DNS hosting, email forwarding, privacy and premium registrations are not supported by this API module. Disable these options for this TLD.'); }
        }
        $years = $this->years();
        $servers = array_values($this->nameservers());
        $secretKey = hash('sha256', $this->scope . '|registration-secret');
        $secret = $this->store->get($secretKey);
        if ($secret === null) {
            $secret = ['auth' => bin2hex(random_bytes(12)) . '!Aa1'];
            $this->store->put($secretKey, $secret);
        }
        $params = ['domainname' => $this->domain, 'period' => $years, 'nss' => $servers, 'authInfoPw' => $secret['auth']];
        if ($this->route['profile'] !== 'VRSN') {
            $contacts = [];
            foreach (['registrant' => '', 'admin' => 'admin', 'tech' => 'tech', 'billing' => 'billing'] as $role => $prefix) {
                $contact = $this->contact($prefix);
                $id = 'W' . substr(hash('sha256', $this->scope . $role . json_encode($contact)), 0, 15);
                $contact += ['id' => $id, 'authInfoPw' => $secret['auth']];
                $this->write('create-contact-' . $id, 'contact.create', fn () => $contact, true);
                $contacts[$role] = $id;
            }
            $params['registrant'] = $contacts['registrant'];
            unset($contacts['registrant']);
            $params['contacts'] = $contacts;
        }
        $this->write('register', 'domain.create', fn () => $params, true);
        return [];
    }

    private function contact(string $prefix): array
    {
        $contact = ['type' => 'int'];
        foreach (['firstname', 'lastname', 'companyname', 'address1', 'address2', 'city', 'state', 'postcode', 'email', 'fullphonenumber'] as $field) {
            $contact[$field] = (string) ($this->params[$prefix . $field] ?? $this->params[$field] ?? '');
        }
        $contact['country'] = (string) ($this->params[$prefix === '' ? 'countrycode' : $prefix . 'country'] ?? $this->params['countrycode'] ?? '');
        return $contact;
    }

    public function transfer(): array
    {
        if ((float) ($this->params['premiumCost'] ?? 0) > 0) { throw new RuntimeException('Premium transfers are not supported by this API module.'); }
        if ((int) ($this->params['domainid'] ?? 0) < 1 || empty($this->params['eppcode'])) {
            throw new RuntimeException('A WHMCS domain ID and transfer authorization code are required.');
        }
        $params = ['domainname' => $this->domain, 'op' => 'request', 'years' => $this->years(), 'authInfoPw' => $this->params['eppcode']];
        // EPP pending is an accepted transfer request, not a completed transfer.
        $this->write('transfer', 'domain.transfer', fn () => $params, true, true);
        return [];
    }

    public function renew(): array
    {
        // Sync may advance WHMCS's expiry while the original paid request is still unresolved.
        // Persist its identity independently of mutable expiry/term inputs before submitting it.
        $outstandingKey = hash('sha256', $this->scope . '|renew-outstanding');
        $outstanding = $this->store->get($outstandingKey);
        if ($outstanding === null) {
            if (!empty($this->params['isInRedemptionGracePeriod'])) {
                throw new RuntimeException('This domain needs restoration through the registrar before it can be renewed.');
            }
            $expiry = (string) ($this->params['expirydate'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $expiry) || $expiry === '0000-00-00') {
                throw new RuntimeException('A valid WHMCS expiry date is required to safely identify this renewal. Sync the domain first.');
            }
            $years = $this->years();
            $outstanding = ['action' => 'renew-' . $expiry . '-' . $years,
                'params' => ['domainname' => $this->domain, 'regperiod' => $years]];
            $this->store->put($outstandingKey, $outstanding);
        }
        try {
            $this->write($outstanding['action'], 'domain.renew', fn () => $outstanding['params'], true);
        } catch (\Throwable $error) {
            // write() removes only definitively rejected requests. Unknown/failed reads keep both records.
            if ($this->store->get(hash('sha256', $this->scope . '|' . $outstanding['action'])) === null) {
                $this->store->remove($outstandingKey);
            }
            throw $error;
        }
        $this->store->remove($outstandingKey);
        return [];
    }

    public function saveNameservers(): array
    {
        $params = ['domainname' => $this->domain] + $this->nameservers();
        $this->write('nameservers', 'domain.update_ns', fn () => $params);
        return [];
    }

    public static function lockedStatus(array $info): bool
    {
        return in_array('clientTransferProhibited', $info['status'] ?? [], true);
    }

    public static function nameserverResult(array $info): array
    {
        $servers = array_values($info['ns'] ?? []);
        $result = [];
        for ($i = 0; $i < 5; ++$i) { $result['ns' . ($i + 1)] = $servers[$i] ?? ''; }
        return $result;
    }

    public function saveLock(): array
    {
        $desired = ($this->params['lockenabled'] ?? '') === 'locked';
        if ($this->store->get(hash('sha256', $this->scope . '|lock')) === null
            && self::lockedStatus($this->info()) === $desired) { return []; }
        // Retrying a timed-out lock action must inspect its saved job before a new action.
        $params = ['domainname' => $this->domain, 'command' => $desired ? 'add' : 'rem', 'status' => 'clientTransferProhibited'];
        $this->write('lock', 'domain.update_status', fn () => $params);
        return [];
    }

    public function contacts(): array
    {
        if ($this->route['profile'] === 'VRSN') { throw new RuntimeException('This registry has no EPP contact objects. Manage registrant details in WHMCS.'); }
        $info = $this->info();
        $ids = ['Registrant' => $info['registrant'] ?? ''];
        $roles = ['admin' => 'Administrative', 'tech' => 'Technical', 'billing' => 'Billing'];
        foreach ($info['contact'] ?? [] as $row) {
            if (isset($roles[$row['type'] ?? ''])) { $ids[$roles[$row['type']]] = $row['id']; }
        }
        return $ids;
    }

    public function getContacts(): array
    {
        $contacts = [];
        foreach ($this->contacts() as $role => $id) {
            if ($id === '') { continue; }
            $info = $this->read('contact.info', ['contact' => $id]);
            $name = explode(' ', (string) ($info['name'] ?? ''), 2);
            $contacts[$role] = ['First Name' => $name[0], 'Last Name' => $name[1] ?? '',
                'Company Name' => $info['org'] ?? '', 'Email Address' => $info['email'] ?? '',
                'Address 1' => $info['street1'] ?? '', 'Address 2' => $info['street2'] ?? '',
                'City' => $info['city'] ?? '', 'State' => $info['state'] ?? '', 'Postcode' => $info['postal'] ?? '',
                'Country' => $info['country'] ?? '', 'Phone Number' => $info['voice'] ?? ''];
        }
        return $contacts;
    }

    public function saveContacts(): array
    {
        // Update owned contacts in place; shared contacts also change at their other domains.
        $ids = $this->contacts();
        foreach ($this->params['contactdetails'] ?? [] as $role => $fields) {
            if (!isset($ids[$role]) || $ids[$role] === '') { throw new RuntimeException('The registry has no ' . $role . ' contact to update.'); }
            $map = ['First Name' => 'firstname', 'Last Name' => 'lastname', 'Company Name' => 'companyname',
                'Email Address' => 'email', 'Address 1' => 'address1', 'Address 2' => 'address2', 'City' => 'city',
                'State' => 'state', 'Postcode' => 'postcode', 'Country' => 'country', 'Phone Number' => 'fullphonenumber'];
            $params = ['id' => $ids[$role], 'type' => 'int'];
            foreach ($map as $label => $field) { $params[$field] = (string) ($fields[$label] ?? ''); }
            $this->write('contact-update-' . $role, 'contact.update', fn () => $params);
        }
        return [];
    }

    public function host(string $action): array
    {
        $params = ['hostname' => self::hostname((string) ($this->params['nameserver'] ?? ''))];
        if ($action !== 'delete') {
            $ip = (string) ($this->params[$action === 'create' ? 'ipaddress' : 'newipaddress'] ?? '');
            if (!filter_var($ip, FILTER_VALIDATE_IP)) { throw new RuntimeException('A valid nameserver IP address is required.'); }
            $params[$action === 'create' ? 'ipaddress' : 'newipaddress'] = $ip;
            if ($action === 'update') { $params['currentipaddress'] = (string) ($this->params['currentipaddress'] ?? ''); }
        }
        $this->write('host-' . $action . '-' . $params['hostname'], 'host.' . $action, fn () => $params);
        return [];
    }

    public function delete(): array
    {
        $this->write('delete', 'domain.delete', fn () => ['domainname' => $this->domain]);
        return [];
    }

    public static function expiry(array $info): string
    {
        $date = substr((string) ($info['exDate'] ?? ''), 0, 10);
        $parts = explode('-', $date);
        if (count($parts) !== 3 || !ctype_digit(implode('', $parts)) || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            throw new RuntimeException('The registry did not return a valid expiry date.');
        }
        return $date;
    }

    public function sync(): array
    {
        $info = $this->info();
        $pending = array_filter($info['status'] ?? [], fn ($status) => str_starts_with($status, 'pending'));
        return ['expirydate' => self::expiry($info), 'active' => $pending === []];
    }

    public function transferSync(): array
    {
        $key = hash('sha256', $this->scope . '|transfer');
        $saved = $this->store->get($key);
        if ($saved === null) { throw new RuntimeException('No saved Namingo transfer request exists for this WHMCS domain.'); }
        $job = isset($saved['job']) ? $this->api->request('GET', '/v1/jobs/' . rawurlencode($saved['job']))
            : $this->api->job($saved['registry'], $saved['command'], $saved['params'], $saved['idempotency']);
        if (($job['status'] ?? '') === 'rejected') { return ['failed' => true, 'reason' => 'Namingo transfer rejected; job ' . $job['job_uuid']]; }
        if (in_array($job['status'] ?? '', ['queued', 'processing'], true)) { return []; }
        ApiClient::result($job, true);
        $result = $this->read('domain.transfer', ['domainname' => $this->domain, 'op' => 'query']);
        if (in_array($result['trStatus'] ?? '', ['clientApproved', 'serverApproved'], true)) {
            return ['completed' => true, 'expirydate' => self::expiry($this->info())];
        }
        if (in_array($result['trStatus'] ?? '', ['clientRejected', 'clientCancelled', 'serverCancelled'], true)) {
            return ['failed' => true, 'reason' => 'Registry transfer status: ' . $result['trStatus']];
        }
        return [];
    }

    public function availability(): bool
    {
        $result = $this->read('domain.check', ['domains' => [$this->domain]]);
        foreach ($result['domains'] ?? [] as $row) {
            if (strtolower((string) ($row['name'] ?? '')) === $this->domain && is_bool($row['avail'] ?? null)) { return $row['avail']; }
        }
        throw new RuntimeException('Namingo returned no availability result for this domain.');
    }
}
