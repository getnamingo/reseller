<?php

declare(strict_types=1);

/** Namingo reseller API adapter for FOSSBilling 0.8.9. */
require_once __DIR__ . '/Namingo/Store.php';
require_once __DIR__ . '/Namingo/ApiClient.php';
require_once __DIR__ . '/Namingo/Registrar.php';

use Namingo\FossBilling\ApiClient;
use Namingo\FossBilling\Registrar;
use Namingo\FossBilling\Store;

class Registrar_Adapter_Namingo extends Registrar_AdapterAbstract
{
    protected array $configuration;
    private ?\Doctrine\DBAL\Connection $stateConnection = null;

    public function __construct(array $options)
    {
        $this->configuration = $options;
        try { new ApiClient($this->apiConfiguration()); }
        catch (Throwable $error) { throw new Registrar_Exception($error->getMessage()); }
    }

    public static function getConfig(): array
    {
        return [
            'label' => 'Manage domains through the Namingo reseller API. Supports generic, UA and VRSN profiles.',
            'form' => [
                'api_url' => ['text', ['label' => 'API base URL', 'required' => true,
                    'description' => 'HTTPS API URL without /v1, not the reseller panel URL.']],
                'api_username' => ['text', ['label' => 'Reseller username', 'required' => true]],
                'api_password' => ['password', ['label' => 'Reseller API password', 'required' => true, 'secret' => true]],
                'default_registry' => ['text', ['label' => 'Default registry UUID', 'required' => false,
                    'description' => 'Optional fallback for TLDs without a map entry.']],
                'registry_map' => ['textarea', ['label' => 'TLD registry map (JSON)', 'required' => false,
                    'description' => 'Example: {"com":"registry-uuid","org.ua":"other-registry-uuid"}. Longest suffix wins.']],
                'allow_delete' => ['radio', ['label' => 'Allow registry deletion on order cancellation',
                    'multiOptions' => ['0' => 'No', '1' => 'Yes'], 'required' => false,
                    'description' => 'FOSSBilling calls deleteDomain when cancelling or deleting a domain order. Default: No.']],
            ],
        ];
    }

    public static function getSecretFields(): array { return ['api_password']; }

    protected function apiConfiguration(): array
    {
        return ['APIURL' => $this->configuration['api_url'] ?? '',
            'APIUsername' => $this->configuration['api_username'] ?? '',
            'APIPassword' => $this->configuration['api_password'] ?? '',
            'DefaultRegistry' => $this->configuration['default_registry'] ?? '',
            'RegistryMap' => $this->configuration['registry_map'] ?? ''];
    }

    protected function createStore(): Store
    {
        // An outer billing transaction may roll back after a timeout. Request keys must survive it.
        $this->stateConnection ??= \FOSSBilling\Doctrine\DriverManagerFactory::getConnection();
        $pdo = $this->stateConnection->getNativeConnection();
        if (!$pdo instanceof PDO) { throw new RuntimeException('Namingo requires the FOSSBilling PDO MySQL connection.'); }
        return new Store($pdo, new Box_Crypt());
    }

    protected function createApi(Store $store): ApiClient
    {
        return new ApiClient($this->apiConfiguration(), null, $store);
    }

    /** Uses the stable FOSSBilling order ID; never invent an ID for a paid action. */
    protected function parameters(Registrar_Domain $domain): array
    {
        $params = $this->apiConfiguration() + [
            'domainid' => (int) ($this->_order?->id ?? 0),
            'renewal_anchor' => (string) ($this->_order?->expires_at ?? ''),
            'sld' => self::ascii((string) $domain->getSld()),
            'tld' => self::ascii(ltrim((string) $domain->getTld(), '.')),
            'regperiod' => $domain->getRegistrationPeriod(), 'eppcode' => (string) $domain->getEpp(),
            'idprotection' => $domain->getPrivacyEnabled() === true,
        ];
        $expiry = $domain->getExpirationTime();
        if (is_numeric($expiry) && (int) $expiry > 0) { $params['expirydate'] = gmdate('Y-m-d', (int) $expiry); }
        for ($i = 1; $i <= 4; ++$i) {
            $params['ns' . $i] = $domain->{'getNs' . $i}() ? self::ascii((string) $domain->{'getNs' . $i}()) : '';
        }
        foreach (['' => 'Registrar', 'admin' => 'Admin', 'tech' => 'Tech', 'billing' => 'Billing'] as $prefix => $role) {
            $contact = $domain->{'getContact' . $role}() ?? $domain->getContactRegistrar();
            if ($contact === null) { continue; }
            foreach (self::contactParameters($contact) as $field => $value) {
                $params[$prefix . ($field === 'country' && $prefix === '' ? 'countrycode' : $field)] = $value;
            }
        }
        return $params;
    }

    private static function ascii(string $name): string
    {
        if (preg_match('/[^\x00-\x7f]/', $name)) {
            if (!function_exists('idn_to_ascii')) { throw new RuntimeException('Enable PHP intl to use international domain names.'); }
            $name = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($name === false) { throw new RuntimeException('Invalid international domain name.'); }
        }
        return strtolower(rtrim(trim($name), '.'));
    }

    private static function contactParameters(Registrar_Domain_Contact $contact): array
    {
        $number = (string) $contact->getTel();
        $cc = preg_replace('/\D/', '', (string) $contact->getTelCc());
        if (!preg_match('/^\+\d+\.\d+$/D', $number)) {
            $digits = preg_replace('/\D/', '', $number);
            if (str_starts_with($number, '+') && $cc !== '' && str_starts_with($digits, $cc)) { $digits = substr($digits, strlen($cc)); }
            $number = $cc !== '' && $digits !== '' ? '+' . $cc . '.' . $digits : '';
        }
        return ['firstname' => (string) $contact->getFirstName(), 'lastname' => (string) $contact->getLastName(),
            'companyname' => (string) $contact->getCompany(), 'email' => (string) $contact->getEmail(),
            'address1' => (string) $contact->getAddress1(),
            'address2' => trim((string) $contact->getAddress2() . ' ' . (string) $contact->getAddress3()),
            'city' => (string) $contact->getCity(), 'state' => (string) $contact->getState(),
            'postcode' => (string) $contact->getZip(), 'country' => (string) $contact->getCountry(), 'fullphonenumber' => $number];
    }

    protected function run(Registrar_Domain $domain, callable $callback): mixed
    {
        try {
            if ($this->_testMode) {
                throw new RuntimeException('Namingo has no simulated test mode. Configure a separate registrar instance with sandbox API credentials and leave the Test mode checkbox off.');
            }
            $store = $this->createStore();
            $service = new Registrar($this->parameters($domain), $this->createApi($store), $store);
            return $service->locked($callback);
        } catch (Registrar_Exception $error) { throw $error; }
        catch (Throwable $error) { throw new Registrar_Exception($error->getMessage()); }
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        return $this->run($domain, fn (Registrar $service) => $service->availability());
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        if ($this->isDomainAvailable($domain)) {
            throw new Registrar_Exception('This domain is available for registration, so it cannot be transferred.');
        }
        // Existence is a precheck, not a guarantee: the registry validates authorization and eligibility.
        return true;
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        return $this->run($domain, function (Registrar $service): bool { $service->register(); return true; });
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        return $this->run($domain, function (Registrar $service): bool { $service->transfer(); return true; });
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        return $this->run($domain, function (Registrar $service): bool { $service->renew(); return true; });
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        return $this->run($domain, function (Registrar $service): bool { $service->saveNameservers(); return true; });
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        return $this->run($domain, function (Registrar $service): bool { $service->saveContacts(); return true; });
    }

    public function lock(Registrar_Domain $domain): bool
    {
        return $this->run($domain, function (Registrar $service): bool { $service->saveLock(true); return true; });
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        return $this->run($domain, function (Registrar $service): bool { $service->saveLock(false); return true; });
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        return $this->run($domain, function (Registrar $service): string {
            $info = $service->info();
            if (empty($info['authInfo'])) { throw new RuntimeException('The registry did not return an authorization code. Contact the registrar.'); }
            return (string) $info['authInfo'];
        });
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        return $this->run($domain, function (Registrar $service) use ($domain): Registrar_Domain {
            $info = $service->info();
            $result = clone $domain;
            Registrar::expiry($info);
            $expiry = strtotime((string) ($info['exDate'] ?? ''));
            if ($expiry === false) { throw new RuntimeException('The registry did not return a valid expiry date.'); }
            $result->setExpirationTime($expiry)->setLocked(Registrar::lockedStatus($info))->setPrivacyEnabled(false);
            if (!empty($info['crDate'])) {
                $created = strtotime($info['crDate']);
                if ($created !== false) { $result->setRegistrationTime($created); }
            }
            $nameservers = Registrar::nameserverResult($info);
            for ($i = 1; $i <= 4; ++$i) { $result->{'setNs' . $i}($nameservers['ns' . $i]); }
            try { $contacts = $service->getContacts(); }
            catch (RuntimeException $error) {
                // Transferred domains may reference contacts the reseller API does not own.
                // Keep the supplied billing contact; never overwrite it with empty registry data.
                if ($error->getCode() !== 403 && !$service->contactless()) { throw $error; }
                $contacts = [];
            }
            foreach (['Registrant' => 'Registrar', 'Administrative' => 'Admin', 'Technical' => 'Tech', 'Billing' => 'Billing'] as $role => $setter) {
                if (!isset($contacts[$role])) { continue; }
                $row = $contacts[$role];
                $phone = explode('.', $row['Phone Number'] ?? '', 2);
                $contact = (new Registrar_Domain_Contact())->setFirstName($row['First Name'])->setLastName($row['Last Name'])
                    ->setCompany($row['Company Name'])->setEmail($row['Email Address'])
                    ->setAddress1($row['Address 1'])->setAddress2($row['Address 2'])->setCity($row['City'])
                    ->setState($row['State'])->setZip($row['Postcode'])->setCountry($row['Country']);
                if (count($phone) === 2) { $contact->setTelCc(ltrim($phone[0], '+'))->setTel($phone[1]); }
                $result->{'setContact' . $setter}($contact);
            }
            if ($result->getContactRegistrar() === null) { $result->setContactRegistrar(new Registrar_Domain_Contact()); }
            return $result;
        });
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        if (($this->configuration['allow_delete'] ?? '0') !== '1' && ($this->configuration['allow_delete'] ?? null) !== 1) {
            throw new Registrar_Exception('Registry deletion on cancellation is disabled. Use the reseller panel or explicitly enable deletion in the Namingo registrar settings.');
        }
        return $this->run($domain, function (Registrar $service): bool { $service->delete(); return true; });
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): never
    {
        throw new Registrar_Exception('The Namingo reseller API does not provide WHOIS privacy protection.');
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): never
    {
        throw new Registrar_Exception('The Namingo reseller API does not provide WHOIS privacy protection.');
    }
}
