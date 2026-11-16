<?php

/** Namingo reseller API registrar module for WHMCS 9.1.x. */
declare(strict_types=1);

if (!defined('WHMCS')) { exit('This file cannot be accessed directly'); }

require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/Store.php';
require_once __DIR__ . '/lib/Registrar.php';

use Namingo\Whmcs\ApiClient;
use Namingo\Whmcs\Registrar;
use Namingo\Whmcs\Store;
use WHMCS\Database\Capsule;
use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;

function namingo_MetaData(): array
{
    return ['DisplayName' => 'Namingo Reseller API', 'APIVersion' => '1.1'];
}

function namingo_getConfigArray(): array
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'Namingo Reseller API'],
        'APIURL' => ['FriendlyName' => 'API base URL', 'Type' => 'text', 'Size' => '60', 'Description' => 'HTTPS URL, without /v1; use the API endpoint, not the reseller panel.'],
        'APIUsername' => ['FriendlyName' => 'Reseller username', 'Type' => 'text', 'Size' => '40'],
        'APIPassword' => ['FriendlyName' => 'Reseller API password', 'Type' => 'password', 'Size' => '40'],
        'DefaultRegistry' => ['FriendlyName' => 'Default registry UUID', 'Type' => 'text', 'Size' => '40', 'Description' => 'Optional fallback when a TLD is not in the map.'],
        'RegistryMap' => ['FriendlyName' => 'TLD registry map (JSON)', 'Type' => 'textarea', 'Rows' => '6', 'Cols' => '70', 'Description' => 'Example: {"com":"registry-uuid","co.uk":"another-registry-uuid"}. Longest suffix wins.'],
    ];
}

function namingo_config_validate(array $params): void
{
    try {
        $store = new Store(Capsule::connection()->getPdo());
        $api = new ApiClient($params, null, $store);
        $api->request('GET', '/v1/account');
        $registries = $api->request('GET', '/v1/registries');
        $map = json_decode(trim((string) ($params['RegistryMap'] ?? '')) ?: '{}', true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($map)) { throw new RuntimeException('Registry map must be a JSON object.'); }
        $ids = array_values($map);
        if (!empty($params['DefaultRegistry'])) { $ids[] = trim($params['DefaultRegistry']); }
        if ($ids === []) { throw new RuntimeException('Enter a default registry UUID or a TLD registry map.'); }
        foreach ($ids as $id) {
            if (!is_string($id) || !in_array($id, array_column($registries, 'registry_uuid'), true)) {
                throw new RuntimeException('A configured registry UUID is not enabled for this reseller.');
            }
        }
    } catch (Throwable $error) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration($error->getMessage());
    }
}

/** Common error-array contract; GetDomainInformation deliberately throws per WHMCS SDK. */
function namingo_call(array $params, string $method, array $args = []): mixed
{
    try {
        if ($method === 'renew' && empty($params['expirydate']) && !empty($params['domainid'])) {
            $params['expirydate'] = Capsule::table('tbldomains')->where('id', $params['domainid'])->value('expirydate');
        }
        $store = new Store(Capsule::connection()->getPdo());
        $registrar = new Registrar($params, new ApiClient($params, null, $store), $store);
        return $registrar->locked(fn ($service) => $service->$method(...$args));
    } catch (Throwable $error) {
        return ['error' => $error->getMessage()];
    }
}

function namingo_RegisterDomain(array $params): array { return namingo_call($params, 'register'); }
function namingo_TransferDomain(array $params): array { return namingo_call($params, 'transfer'); }
function namingo_RenewDomain(array $params): array { return namingo_call($params, 'renew'); }
function namingo_SaveNameservers(array $params): array { return namingo_call($params, 'saveNameservers'); }
function namingo_SaveRegistrarLock(array $params): array { return namingo_call($params, 'saveLock'); }
function namingo_GetContactDetails(array $params): array { return namingo_call($params, 'getContacts'); }
function namingo_SaveContactDetails(array $params): array { return namingo_call($params, 'saveContacts'); }
function namingo_RegisterNameserver(array $params): array { return namingo_call($params, 'host', ['create']); }
function namingo_ModifyNameserver(array $params): array { return namingo_call($params, 'host', ['update']); }
function namingo_DeleteNameserver(array $params): array { return namingo_call($params, 'host', ['delete']); }
function namingo_RequestDelete(array $params): array { return namingo_call($params, 'delete'); }
function namingo_Sync(array $params): array { return namingo_call($params, 'sync'); }
function namingo_TransferSync(array $params): array { return namingo_call($params, 'transferSync'); }

function namingo_GetNameservers(array $params): array
{
    $info = namingo_call($params, 'info');
    if (isset($info['error'])) { return $info; }
    return Registrar::nameserverResult($info);
}

function namingo_GetRegistrarLock(array $params): array|string
{
    $info = namingo_call($params, 'info');
    return isset($info['error']) ? $info : (Registrar::lockedStatus($info) ? 'locked' : 'unlocked');
}

function namingo_GetEPPCode(array $params): array
{
    $info = namingo_call($params, 'info');
    if (isset($info['error'])) { return $info; }
    if (empty($info['authInfo'])) { return ['error' => 'The registry did not return an authorization code. Contact the registrar.']; }
    return ['eppcode' => $info['authInfo']];
}

function namingo_GetDomainInformation(array $params): \WHMCS\Domain\Registrar\Domain
{
    $info = namingo_call($params, 'info');
    if (isset($info['error'])) { throw new RuntimeException($info['error']); }
    $pending = array_filter($info['status'] ?? [], fn ($status) => str_starts_with($status, 'pending'));
    $status = $pending === [] ? \WHMCS\Domain\Registrar\Domain::STATUS_ACTIVE : \WHMCS\Domain\Registrar\Domain::STATUS_INACTIVE;
    if (in_array('pendingDelete', $info['status'] ?? [], true)) { $status = \WHMCS\Domain\Registrar\Domain::STATUS_PENDING_DELETE; }
    return (new \WHMCS\Domain\Registrar\Domain())
        ->setDomain(Registrar::domain($params))
        ->setNameservers(Registrar::nameserverResult($info))
        ->setRegistrationStatus($status)
        ->setTransferLock(Registrar::lockedStatus($info))
        ->setExpiryDate(\WHMCS\Carbon::createFromFormat('Y-m-d', Registrar::expiry($info)))
        ->setRestorable(false)->setIdProtectionStatus(false)
        ->setDnsManagementStatus(false)->setEmailForwardingStatus(false);
}

function namingo_CheckAvailability(array $params): ResultsList|array
{
    try {
        $results = new ResultsList();
        foreach ($params['tldsToInclude'] ?? [] as $tld) {
            $tld = ltrim((string) $tld, '.');
            $sld = (string) (!empty($params['punyCodeSearchTerm']) ? $params['punyCodeSearchTerm'] : ($params['searchTerm'] ?? ''));
            $domain = Registrar::domain(['sld' => $sld, 'tld' => $tld]);
            // Missing mapping is unsupported; connection and registry errors remain errors.
            try { Registrar::registry($domain, $params); }
            catch (RuntimeException) {
                $item = new SearchResult($sld, $tld);
                $item->setStatus(SearchResult::STATUS_TLD_NOT_SUPPORTED);
                $results->append($item);
                continue;
            }
            $lookup = array_replace($params, ['sld' => $sld, 'tld' => $tld]);
            unset($lookup['domain_punycode'], $lookup['sld_punycode'], $lookup['tld_punycode']);
            $available = namingo_call($lookup, 'availability');
            if (is_array($available) && isset($available['error'])) { return $available; }
            $item = new SearchResult($sld, $tld);
            $item->setStatus($available ? SearchResult::STATUS_NOT_REGISTERED : SearchResult::STATUS_REGISTERED);
            $results->append($item);
        }
        return $results;
    } catch (Throwable $error) { return ['error' => $error->getMessage()]; }
}
