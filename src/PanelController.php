<?php

declare(strict_types=1);

namespace Namingo\ResellerApi;

use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Throwable;

final class PanelController
{
    private ?array $user = null;
    private const SECTIONS = [
        'resellers' => ['user.create', 'user.update', 'user.password', 'user.status', 'user.role', 'tokens.revoke'],
        'registries' => ['registry.save', 'registry.status', 'registry.access', 'tld.save'],
        'tlds' => ['tld.save', 'tld.access', 'tld.delete', 'tld.unassign'],
        'prices' => ['price.set', 'price.batch', 'price.delete', 'price.group_update', 'price.group_delete'],
        'deposits' => ['deposit.approve', 'deposit.reject'],
        'jobs' => ['job.resolve', 'job.mark_unknown'],
        'ownership' => ['object.assign'],
    ];

    public function __construct(private readonly PDO $pdo, private readonly Twig $view, private readonly PanelApi $api)
    {
    }

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        $input = $request->getParsedBody();
        $input = is_array($input) ? $input : [];
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        $this->user = null;
        try {
            if (!in_array($method, ['GET', 'POST'], true)) {
                throw new ApiException(405, 'method_not_allowed', 'Use GET or POST.');
            }
            if ($request->getBody()->getSize() > Config::int('API_MAX_BODY_BYTES', 262144)) {
                throw new ApiException(413, 'body_too_large', 'The submitted form is too large.');
            }
            if ($method === 'POST') {
                PanelSession::csrf($input['csrf'] ?? null, $request->getHeaderLine('Origin'));
            }
            $this->user = PanelSession::user($this->pdo);
            if ($path === '/login') {
                if ($this->user !== null) {
                    return $this->redirect($response, '/');
                }
                if ($method === 'POST') {
                    if (!is_string($input['username'] ?? null) || !is_string($input['password'] ?? null)) {
                        throw new ApiException(422, 'invalid_login', 'Enter your username and password.');
                    }
                    PanelSession::login(Auth::login($this->pdo, $input['username'], $input['password'], $ip));
                    return $this->redirect($response, '/');
                }
                return $this->render($response, 'login.twig', ['title' => 'Sign in']);
            }
            if ($this->user === null) {
                return $this->redirect($response, '/login');
            }
            if ($path === '/logout' && $method === 'POST') {
                Auth::revoke($this->pdo, $this->user['token_id']);
                PanelSession::clear();
                return $this->redirect($response, '/login');
            }
            if (in_array($path, ['/transfers', '/admin/transfers'], true)) {
                $admin = $path === '/admin/transfers';
                if ($admin && (int) $this->user['role'] !== 0) { throw new ApiException(403, 'admin_required', 'Administrator access is required.'); }
                return $this->transfers($request, $response, $admin, $input, $ip);
            }
            if (preg_match('#^(/admin)?/contacts(?:/([a-f0-9-]{36}))?$#D', $path, $match)) {
                $admin = ($match[1] ?? '') === '/admin';
                if ($admin && (int) $this->user['role'] !== 0) { throw new ApiException(403, 'admin_required', 'Administrator access is required.'); }
                return $this->contacts($request, $response, $admin, $match[2] ?? null, $input, $ip);
            }
            if (preg_match('#^(/admin)?/domains(?:/([a-f0-9-]{36})/([a-zA-Z0-9.-]+))?$#D', $path, $match)) {
                $admin = ($match[1] ?? '') === '/admin';
                if ($admin && (int) $this->user['role'] !== 0) { throw new ApiException(403, 'admin_required', 'Administrator access is required.'); }
                return $this->domains($request, $response, $admin, $match[2] ?? null, $match[3] ?? null, $input, $ip);
            }
            if (str_starts_with($path, '/admin/')) {
                if ((int) $this->user['role'] !== 0) {
                    throw new ApiException(403, 'admin_required', 'Administrator access is required.');
                }
                if ($path === '/admin/tlds' && $method === 'GET') {
                    return $this->redirect($response, '/admin/registries');
                }
                if (preg_match('#^/admin/registries/([a-f0-9-]{36})(?:/(tlds|permissions))?$#D', $path, $match)) {
                    $registry = PanelData::rows($this->pdo, 'SELECT id, name, profile, enabled FROM registries WHERE id = ?', [$match[1]])[0] ?? null;
                    if ($registry === null) { throw new ApiException(404, 'registry_not_found', 'Registry was not found.'); }
                    $view = $match[2] ?? 'edit';
                    if ($method === 'POST') {
                        $allowed = match ($view) {
                            'edit' => ['registry.save', 'registry.status', 'registry.access'],
                            'tlds' => ['tld.save', 'tld.delete'],
                            'permissions' => ['tld.access', 'tld.unassign'],
                        };
                        $action = $input['action'] ?? '';
                        if (!in_array($action, $allowed, true)) { throw new ApiException(422, 'invalid_action', 'Choose an action offered on this registry page.'); }
                        Admin::perform($this->pdo, $this->user['id'], $action, array_replace($input, ['registry_id' => $registry['id']]), $ip);
                        $_SESSION['flash'] = 'Registry settings saved.';
                        $page = $this->page($request);
                        return $this->redirect($response, $path . ($page > 1 ? '?page=' . $page : ''));
                    }
                    return $this->registryPage($response, $registry, $view, $this->page($request), $path);
                }
                if (preg_match('#^/admin/resellers/([a-f0-9-]{36})$#D', $path, $match)) {
                    $accounts = PanelData::rows($this->pdo, 'SELECT id, username, display_name, company, email, phone, address, country_code, currency, status, role FROM resellers WHERE id = ?', [$match[1]]);
                    if ($accounts === []) { throw new ApiException(404, 'user_not_found', 'Reseller was not found.'); }
                    if ($method === 'POST') {
                        $action = $input['action'] ?? 'user.update';
                        if (!in_array($action, ['user.update', 'user.status', 'user.role', 'user.password', 'tokens.revoke'], true)) {
                            throw new ApiException(422, 'invalid_action', 'Choose an action offered on this reseller page.');
                        }
                        // The URL selects the account; submitted UUIDs cannot retarget an action.
                        Admin::perform($this->pdo, $this->user['id'], $action, array_replace($input, ['reseller_id' => $match[1]]), $ip);
                        $_SESSION['flash'] = 'Account saved. Username, password or status changes may revoke its API tokens and panel sessions.';
                        return $this->redirect($response, $path);
                    }
                    return $this->render($response, 'account_edit.twig', ['title' => 'Manage reseller: ' . $accounts[0]['username'], 'section' => 'resellers', 'account' => $accounts[0]]);
                }
                if ($method === 'GET' && preg_match('#^/admin/jobs/([a-f0-9-]{36})$#D', $path, $match)) {
                    $job = Ledger::fetchJob($this->pdo, $match[1]);
                    $owner = PanelData::rows($this->pdo, 'SELECT r.id, r.username FROM resellers r JOIN epp_jobs j ON j.reseller_id = r.id WHERE j.id = ?', [$match[1]])[0];
                    return $this->render($response, 'job.twig', ['title' => 'Investigate job', 'job' => $job, 'admin_job' => true, 'owner' => $owner,
                        'result' => json_encode($job['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                }
                $section = substr($path, 7);
                if (!isset(self::SECTIONS[$section])) {
                    throw new ApiException(404, 'not_found', 'Page was not found.');
                }
                if ($method === 'POST') {
                    $action = $input['action'] ?? null;
                    if (!in_array($action, self::SECTIONS[$section], true)) {
                        throw new ApiException(422, 'invalid_action', 'Choose an action offered on this page.');
                    }
                    if ($section === 'registries' && $action === 'registry.save' && ($input['registry_id'] ?? '') !== '') {
                        throw new ApiException(422, 'invalid_action', 'Open the registry edit page to update an existing registry.');
                    }
                    $id = Admin::perform($this->pdo, $this->user['id'], $action, $input, $ip);
                    $_SESSION['flash'] = 'Saved. Reference: ' . $id;
                    $page = $this->page($request);
                    return $this->redirect($response, $path . ($page > 1 ? '?page=' . $page : ''));
                }
                return $this->admin($response, $section, $this->page($request));
            }
            if ($path === '/wallet/deposits' && $method === 'POST') {
                $amount = Util::money($input['amount'] ?? null);
                $reference = $input['reference'] ?? null;
                if (!is_string($reference) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $reference) !== 1) {
                    throw new ApiException(422, 'invalid_reference', 'Deposit reference must contain 8-128 safe ASCII characters.');
                }
                $deposit = $this->api->deposit($_SESSION['api_token'], $amount, $this->user['currency'], $reference);
                if (!is_string($deposit['deposit_uuid'] ?? null) || !Util::isUuid($deposit['deposit_uuid'])) {
                    throw new ApiException(502, 'invalid_deposit', 'The API did not return a deposit reference. Check deposit history before retrying with the same reference.');
                }
                $_SESSION['flash'] = 'Deposit request recorded. Reference: ' . $deposit['deposit_uuid'] . '. Funds are credited only after administrator approval.';
                return $this->redirect($response, '/wallet');
            }
            if ($method === 'POST' && preg_match('#^/jobs/([a-f0-9-]{36})/reconcile$#D', $path, $match)) {
                $this->api->schedule($_SESSION['api_token'], $match[1]);
                return $this->redirect($response, '/jobs/' . $match[1]);
            }
            if ($path === '/lifecycle' && $method === 'POST') {
                $command = $input['command'] ?? '';
                if (!in_array($command, ['domain.restore', 'domain.restore_report', 'domain.transfer'], true)
                    || !is_string($input['registry_id'] ?? null) || !Util::isUuid($input['registry_id'])
                    || !is_string($input['key'] ?? null) || !Util::isUuid($input['key'])) {
                    throw new ApiException(422, 'invalid_lifecycle_action', 'Invalid lifecycle action.');
                }
                $params = ['domainname' => Ownership::name($input['domainname'] ?? null, 'domain')];
                if ($command === 'domain.transfer') {
                    if (!in_array($input['op'] ?? '', ['query', 'cancel'], true)) {
                        throw new ApiException(422, 'invalid_lifecycle_action', 'Only transfer query or cancellation is offered here.');
                    }
                    $params['op'] = $input['op'];
                } elseif ($command === 'domain.restore_report') {
                    foreach (['pre_data', 'post_data', 'deleted_at', 'restored_at', 'reason', 'other'] as $field) { $params[$field] = $input[$field] ?? ''; }
                    $params['statements'] = [$input['statement1'] ?? '', $input['statement2'] ?? ''];
                    Rgp::validate($params);
                }
                $job = $this->api->lifecycle($_SESSION['api_token'], $input['registry_id'], $command, $params, $input['key']);
                return $this->redirect($response, '/jobs/' . $job['job_uuid']);
            }
            if ($path === '/checks' && $method === 'POST') {
                return $this->redirect($response, '/checks?batch=' . $this->submitChecks($input));
            }
            if ($method !== 'GET') {
                throw new ApiException(405, 'method_not_allowed', 'This page is read-only.');
            }
            if (preg_match('#^/jobs/([a-f0-9-]{36})$#D', $path, $matches)) {
                $job = $this->api->detail($_SESSION['api_token'], $matches[1]);
                return $this->render($response, 'job.twig', ['title' => 'Job details', 'job' => $job, 'key' => Util::uuid(),
                    'result' => json_encode($job['result'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
            if (preg_match('#^/checks/([a-f0-9-]{36})$#D', $path, $matches)) {
                $job = $this->api->job($_SESSION['api_token'], $matches[1]);
                return $this->render($response, 'check.twig', ['title' => 'Availability result', 'job' => $job,
                    'result' => json_encode($job['result'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
            return $this->reseller($response, $path, $this->page($request), $request->getQueryParams());
        } catch (ApiException $e) {
            return $this->render($response->withStatus($e->httpStatus), $path === '/login' ? 'login.twig' : 'error.twig',
                ['title' => $path === '/login' ? 'Sign in' : 'Request could not be completed', 'error' => $e->getMessage()]);
        } catch (PDOException $e) {
            error_log('panel.database_error sqlstate=' . $e->getCode());
            return $this->render($response->withStatus(409), 'error.twig', ['title' => 'Change could not be saved',
                'error' => 'The record conflicts with existing data, references an unavailable record, or the database is temporarily unavailable.']);
        } catch (\JsonException) {
            return $this->render($response->withStatus(422), 'error.twig', ['title' => 'Invalid JSON', 'error' => 'Enter a valid registry configuration object.']);
        } catch (\RuntimeException $e) {
            // Registry/config validation messages contain field names, never stored registry secrets.
            if (str_starts_with($path, '/admin/')) {
                return $this->render($response->withStatus(422), 'error.twig', ['title' => 'Invalid configuration', 'error' => $e->getMessage()]);
            }
            error_log('panel.request_failed type=' . get_class($e));
            return $this->render($response->withStatus(503), 'error.twig', ['title' => 'Service unavailable', 'error' => 'The request could not be completed.']);
        } catch (Throwable $e) {
            error_log('panel.request_failed type=' . get_class($e));
            return $this->render($response->withStatus(503), 'error.twig', ['title' => 'Service unavailable', 'error' => 'The request could not be completed.']);
        }
    }


    private function transfers(ServerRequestInterface $request, ResponseInterface $response, bool $admin, array $input, string $ip): ResponseInterface
    {
        $path = $admin ? '/admin/transfers' : '/transfers';
        $actor = $admin ? $this->user : array_replace($this->user, ['role' => 1]);
        if ($request->getMethod() === 'POST') {
            Domains::refresh($this->pdo, $actor);
            $op = DomainParams::text($input, 'action');
            if ($op === 'request') {
                $batch = Domains::submit($this->pdo, $actor, array_replace($input, ['action' => 'transfer-request']), $ip);
            } else {
                if (!in_array($op, ['query', 'cancel', 'approve', 'reject'], true)) { throw new ApiException(422, 'invalid_action', 'Choose an available transfer action.'); }
                $transfer = Transfers::get($this->pdo, $actor, DomainParams::text($input, 'transfer_id'));
                if (!in_array($op, Transfers::actions($transfer), true)) { throw new ApiException(409, 'transfer_action_unavailable', 'This action is no longer available. Refresh the transfers list.'); }
                $domain = Domains::owned($this->pdo, $actor, $transfer['registry_id'], $transfer['domainname']);
                if ($domain['reseller_id'] !== $transfer['reseller_id']) { throw new ApiException(403, 'object_not_owned', 'Transfer belongs to a different account.'); }
                $batch = Domains::submit($this->pdo, $actor, array_replace($input, ['action' => 'transfer-' . $op]), $ip, $domain);
            }
            return $this->redirect($response, $path . '?batch=' . $batch);
        }
        $batch = $request->getQueryParams()['batch'] ?? null;
        if ($batch !== null && (!is_string($batch) || !Util::isUuid($batch))) { throw new ApiException(422, 'invalid_batch', 'Invalid operation reference.'); }
        $active = Domains::refresh($this->pdo, $actor, $batch);
        $ops = $batch === null ? [] : Domains::publicOperations(Domains::operations($this->pdo, $actor, $batch));
        $page = $this->page($request);
        $listing = Transfers::listing($this->pdo, $actor, $page);
        $version = hash('sha256', Util::json([$listing, $ops]));
        $pending = $active !== [] || array_filter($listing['rows'], fn ($r) => in_array($r['job_status'], ['queued', 'processing', 'pending'], true)) !== [];
        if (($request->getQueryParams()['format'] ?? '') === 'json') {
            $response->getBody()->write(Util::json(['version' => $version, 'pending' => $pending]));
            return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
        }
        return $this->render($response, 'transfers.twig', $listing + ['title' => $admin ? 'Manage transfers' : 'Your transfers',
            'section' => $admin ? 'transfers' : null, 'path' => $path, 'key' => Util::uuid(), 'operations' => $ops,
            'pending' => $pending, 'version' => $version, 'batch' => $batch, 'page' => $page, 'paginate' => true,
            'resellers' => $admin ? PanelData::rows($this->pdo, 'SELECT id, username FROM resellers ORDER BY username') : []]);
    }

    private function domains(ServerRequestInterface $request, ResponseInterface $response, bool $admin, ?string $registry, ?string $name, array $input, string $ip): ResponseInterface
    {
        $path = $admin ? '/admin/domains' : '/domains';
        $actor = $admin ? $this->user : array_replace($this->user, ['role' => 1]);
        $domain = $registry === null ? null : Domains::owned($this->pdo, $actor, $registry, $name);
        $url = $domain === null ? $path : $path . '/' . $domain['registry_id'] . '/' . $domain['object_name'];
        if ($request->getMethod() === 'POST') {
            $batch = Domains::submit($this->pdo, $actor, $input, $ip, $domain);
            return $this->redirect($response, $url . '?batch=' . $batch);
        }
        $batch = $request->getQueryParams()['batch'] ?? null;
        if ($batch !== null && (!is_string($batch) || !Util::isUuid($batch))) { throw new ApiException(422, 'invalid_batch', 'Invalid operation reference.'); }
        $active = Domains::refresh($this->pdo, $actor, $batch);
        $ops = Domains::operations($this->pdo, $actor, $batch, $domain);
        if ($domain !== null && $ops === [] && $batch === null && $domain['active'] && $domain['job_id'] === null) {
            Domains::submit($this->pdo, $actor, ['key' => Util::uuid(), 'action' => 'sync'], $ip, $domain);
            $ops = Domains::operations($this->pdo, $actor, null, $domain);
        }
        $public = Domains::publicOperations($ops);
        $version = hash('sha256', Util::json($public));
        $pending = array_filter(array_merge($ops, $active), fn ($o) => in_array($o['status'], ['prepared', 'queued', 'processing', 'pending'], true)) !== [];
        if (($request->getQueryParams()['format'] ?? '') === 'json') {
            $response->getBody()->write(Util::json(['version' => $version, 'pending' => $pending]));
            return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
        }
        $data = ['title' => $admin ? 'Manage domains' : 'Your domains', 'section' => $admin ? 'domains' : null,
            'path' => $path, 'url' => $url, 'key' => Util::uuid(), 'operations' => $public, 'pending' => $pending,
            'version' => $version, 'batch' => $batch, 'page' => $this->page($request),
            'resellers' => $admin ? PanelData::rows($this->pdo, 'SELECT id, username FROM resellers ORDER BY username') : []];
        if ($domain !== null) {
            $allOps = $batch === null ? $ops : Domains::operations($this->pdo, $actor, null, $domain);
            $info = Domains::info($allOps); $contacts = [];
            foreach ($info['contact'] ?? [] as $contact) {
                if (is_array($contact) && isset($contact['type'], $contact['id'])) { $contacts[$contact['type']] = $contact['id']; }
            }
            $data += ['domain' => $domain, 'info' => $info, 'contact_ids' => $contacts, 'hosts' => array_values($info['ns'] ?? []),
                'title' => 'Manage ' . $domain['object_name']];
            $data['title'] = 'Manage ' . $domain['object_name'];
            return $this->render($response, 'domain.twig', $data);
        }
        return $this->render($response, 'domains.twig', $data + Domains::listing($this->pdo, $actor, $data['page']) + ['paginate' => true]);
    }

    private function contacts(ServerRequestInterface $request, ResponseInterface $response, bool $admin, ?string $id, array $input, string $ip): ResponseInterface
    {
        $path = $admin ? '/admin/contacts' : '/contacts';
        // The reseller workspace remains own-account-only, including when an administrator uses it.
        $actor = $admin ? $this->user : array_replace($this->user, ['role' => 1]);
        if ($request->getMethod() === 'POST') {
            $id ??= is_string($input['contact_id'] ?? null) ? $input['contact_id'] : '';
            Contacts::submit($this->pdo, $actor, $id, $input, $ip);
            $_SESSION['flash'] = 'Contact operation submitted. Registry outcomes and EPP errors are shown below.';
            if (!PanelData::rows($this->pdo, 'SELECT id FROM panel_contacts WHERE id = ?', [$id])) { return $this->redirect($response, $path); }
            return $this->redirect($response, $path . '/' . $id);
        }
        $page = $this->page($request);
        $registries = $admin ? PanelData::rows($this->pdo, 'SELECT id, name, profile FROM registries ORDER BY name')
            : PanelData::rows($this->pdo, 'SELECT r.id, r.name, r.profile FROM registries r JOIN reseller_registries a ON a.registry_id = r.id WHERE a.reseller_id = ? AND a.enabled = 1 AND r.enabled = 1 ORDER BY r.name', [$actor['id']]);
        $data = ['title' => $admin ? 'Manage contacts' : 'Your contacts', 'section' => $admin ? 'contacts' : null, 'path' => $path,
            'registry_options' => $registries, 'contact_fields' => ProfileParams::contactFields(), 'key' => Util::uuid(),
            'resellers' => $admin ? PanelData::rows($this->pdo, 'SELECT id, username FROM resellers ORDER BY username') : []];
        if ($id !== null) {
            $contact = Contacts::get($this->pdo, $id, $actor);
            if ($contact === null) {
                if (($request->getQueryParams()['format'] ?? '') === 'json') {
                    $response->getBody()->write(Util::json(['deleted' => true]));
                    return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
                }
                return $this->redirect($response, $path);
            }
            $pending = array_filter($contact['registries'], fn ($link) => in_array($link['status'] ?? '', ['queued', 'processing'], true)) !== [];
            if (($request->getQueryParams()['format'] ?? '') === 'json') {
                $response->getBody()->write(Util::json(['version' => $contact['version'], 'pending' => $pending, 'deleted' => false]));
                return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
            }
            return $this->render($response, 'contact.twig', $data + ['title' => 'Manage contact', 'contact' => $contact, 'pending' => $pending]);
        }
        $listing = Contacts::listing($this->pdo, $actor, $page, $admin);
        return $this->render($response, 'contacts.twig', $data + $listing + ['page' => $page, 'paginate' => true,
            'contact' => ['id' => Util::uuid(), 'reseller_id' => $actor['id'], 'data' => Contacts::defaults(), 'registries' => []]]);
    }

    private function submitChecks(array $input): string
    {
        $key = $input['key'] ?? null;
        if (!is_string($key) || !Util::isUuid($key) || !is_string($input['domains'] ?? null) || strlen($input['domains']) > 15000) {
            throw new ApiException(422, 'invalid_check', 'Enter up to 50 ASCII domain names.');
        }
        $domains = preg_split('/[\s,]+/', trim($input['domains']), -1, PREG_SPLIT_NO_EMPTY);
        if (count($domains) < 1 || count($domains) > 50) {
            throw new ApiException(422, 'invalid_domains', 'Supply between 1 and 50 domain names.');
        }
        $hash = hash('sha256', Util::json($domains));
        if (isset($_SESSION['checks'][$key])) {
            if ($_SESSION['checks'][$key]['hash'] !== $hash) {
                throw new ApiException(409, 'idempotency_conflict', 'This form was already submitted with different domains. Reload the page.');
            }
            return $key;
        }
        $rows = PanelData::checkRoutes($this->pdo, $this->user['id'], $domains);
        $groups = [];
        foreach ($rows as $row) {
            if (isset($row['registry_id'])) { $groups[$row['registry_id']][] = $row['name']; }
        }
        $jobs = [];
        foreach ($groups as $registry => $names) {
            try {
                Access::domains($this->pdo, $registry, $this->user['id'], 'domain.check', ['domains' => $names]);
                $job = $this->api->check($_SESSION['api_token'], $registry, $names, 'check-' . hash('sha256', $key . ':' . $registry), count($domains) > 1);
                if (!is_string($job['job_uuid'] ?? null) || !Util::isUuid($job['job_uuid'])) {
                    throw new ApiException(502, 'invalid_check', 'The API did not return a check reference.');
                }
                if (in_array($job['status'] ?? '', ['succeeded', 'rejected', 'unknown'], true)) { $jobs[$job['job_uuid']] = $job; }
                foreach ($rows as &$row) {
                    if (($row['registry_id'] ?? null) === $registry) { $row['job_uuid'] = $job['job_uuid']; }
                }
                unset($row);
            } catch (ApiException $e) {
                foreach ($rows as &$row) {
                    if (($row['registry_id'] ?? null) === $registry) { $row['status'] = 'Failed'; $row['reason'] = $e->getMessage(); }
                }
                unset($row);
            }
        }
        $_SESSION['checks'][$key] = ['hash' => $hash, 'rows' => $rows, 'jobs' => $jobs];
        $_SESSION['checks'] = array_slice($_SESSION['checks'], -10, null, true);
        return $key;
    }

    private function checkRows(mixed $key): array
    {
        if (!is_string($key) || !isset($_SESSION['checks'][$key])) {
            throw new ApiException(404, 'check_not_found', 'This batch is no longer in your session. Individual checks remain in check history.');
        }
        $rows = $_SESSION['checks'][$key]['rows'];
        $jobs = $_SESSION['checks'][$key]['jobs'] ?? [];
        foreach ($rows as &$row) {
            if (!isset($row['job_uuid'])) { continue; }
            $id = $row['job_uuid'];
            if (!isset($jobs[$id])) {
                try { $jobs[$id] = $this->api->job($_SESSION['api_token'], $id); }
                catch (ApiException $e) {
                    $retryable = $e->httpStatus >= 500 || in_array($e->httpStatus, [408, 429], true);
                    $jobs[$id] = ['status' => $retryable ? 'pending' : 'failed', 'retryable' => $retryable,
                        'result' => ['error' => $e->getMessage()]];
                }
            }
            $job = $jobs[$id];
            $row['retryable'] = $job['retryable'] ?? false;
            if (in_array($job['status'] ?? '', ['succeeded', 'rejected', 'unknown'], true)) { $_SESSION['checks'][$key]['jobs'][$id] = $job; }
            $row['status'] = ucfirst($job['status'] ?? 'unknown');
            $row['reason'] = $job['result']['error'] ?? $job['result']['msg'] ?? $job['result']['message'] ?? '';
            if (!is_string($row['reason'])) { $row['reason'] = ''; }
            if (($job['status'] ?? '') !== 'succeeded') { continue; }
            $row['status'] = 'No result';
            foreach ($job['result']['domains'] ?? [] as $domain) {
                if (strtolower($domain['name'] ?? '') !== $row['name']) { continue; }
                $available = $domain['avail'] ?? null;
                if (in_array($available, [true, 1, '1', 'true'], true)) { $row['status'] = 'Available'; }
                elseif (in_array($available, [false, 0, '0', 'false'], true)) { $row['status'] = 'Unavailable'; }
                $row['reason'] = is_string($domain['reason'] ?? null) ? $domain['reason'] : '';
                break;
            }
        }
        unset($row);
        return $rows;
    }

    private function reseller(ResponseInterface $response, string $path, int $page, array $query = []): ResponseInterface
    {
        $id = $this->user['id'];
        $offset = ($page - 1) * 50;
        $data = ['page' => $page, 'path' => $path];
        switch ($path) {
            case '/':
                $data += ['title' => 'Dashboard', 'jobs' => PanelData::rows($this->pdo,
                    'SELECT id, command, status, price, currency, created_at FROM epp_jobs WHERE reseller_id = ? ORDER BY created_at DESC LIMIT 10', [$id]),
                    'counts' => PanelData::rows($this->pdo, 'SELECT object_type, COUNT(*) AS total FROM object_ownership WHERE reseller_id = ? AND active = 1 GROUP BY object_type', [$id])];
                return $this->render($response, 'dashboard.twig', $data);
            case '/jobs':
                $filters = array_intersect_key($query, array_flip(['status', 'command', 'registry_uuid']));
                $listing = SelfService::listing($this->pdo, $id, 'jobs', $filters + ['limit' => 50, 'offset' => $offset]);
                return $this->render($response, 'jobs.twig', $data + ['title' => 'Job history', 'rows' => $listing['data'],
                    'paginate' => true, 'next' => $listing['pagination']['has_more'], 'filters' => $filters,
                    'query' => http_build_query($filters), 'commands' => array_column(CommandCatalog::all(), 'command'),
                    'statuses' => ['queued', 'processing', 'succeeded', 'rejected', 'unknown', 'pending']]);
            case '/account':
                return $this->render($response, 'account.twig', $data + ['title' => 'Account',
                    'account' => PanelData::rows($this->pdo, 'SELECT id, username, display_name, company, email, phone, address, country_code, role, status, currency, created_at FROM resellers WHERE id = ?', [$id])[0] ?? [],
                    'access' => PanelData::access($this->pdo, $id),
                    'access_columns' => ['registry' => 'Registry', 'registry_id' => 'UUID', 'profile' => 'Profile', 'registry_enabled' => 'Registry enabled', 'access_enabled' => 'Access enabled',
                        'tld' => 'TLD', 'tld_enabled' => 'TLD enabled', 'permitted' => 'TLD permitted']]);
            case '/access':
                return $this->redirect($response, '/account');
            case '/prices':
                $groups = [];
                foreach (PanelData::prices($this->pdo, $id) as $price) {
                    $key = $price['registry_id'] . ':' . $price['tld'];
                    $groups[$key]['registry'] = $price['registry'];
                    $groups[$key]['tld'] = $price['tld'];
                    if ($price['command'] === 'domain.restore') { $groups[$key]['restore'] = $price; }
                    else { $groups[$key]['terms'][(int) $price['period']][$price['command']] = $price; }
                }
                foreach ($groups as &$group) { if (isset($group['terms'])) { ksort($group['terms']); } }
                unset($group);
                return $this->render($response, 'prices.twig', $data + ['title' => 'Your prices', 'price_groups' => $groups]);
            case '/wallet':
                $data += ['title' => 'Wallet and ledger', 'rows' => PanelData::rows($this->pdo,
                    "SELECT kind, available_delta, held_delta, available_after, held_after, currency, description, created_at FROM ledger_entries WHERE reseller_id = ? ORDER BY created_at DESC, id DESC LIMIT 51 OFFSET {$offset}", [$id]),
                    'columns' => ['kind' => 'Type', 'available_delta' => 'Available change', 'held_delta' => 'Held change', 'available_after' => 'Available after', 'held_after' => 'Held after', 'currency' => 'Currency', 'description' => 'Description', 'created_at' => 'Date'],
                    'deposits' => PanelData::rows($this->pdo, 'SELECT id, client_reference, amount, currency, status, created_at FROM deposit_requests WHERE reseller_id = ? ORDER BY created_at DESC LIMIT 50', [$id]), 'deposit_reference' => Util::uuid(), 'paginate' => true];
                break;

            case '/checks':
                $results = isset($query['batch']) ? $this->checkRows($query['batch']) : [];
                $pending = array_filter($results, fn ($row) => in_array($row['status'], ['Queued', 'Processing', 'Pending'], true)) !== [];
                if (($query['format'] ?? '') === 'json') {
                    $response->getBody()->write(Util::json(['rows' => $results, 'pending' => $pending,
                        'retryable' => array_filter($results, fn ($row) => $row['retryable'] ?? false) !== []]));
                    return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
                }
                return $this->render($response, 'checks.twig', $data + ['title' => 'Domain availability', 'key' => Util::uuid(),
                    'access' => PanelData::access($this->pdo, $id), 'batch' => $query['batch'] ?? null,
                    'results' => $results, 'pending' => $pending,
                    'jobs' => PanelData::rows($this->pdo, "SELECT id AS check_id, status, price, currency, created_at FROM epp_jobs WHERE reseller_id = ? AND command = 'domain.check' ORDER BY created_at DESC LIMIT 50", [$id])]);
            default:
                throw new ApiException(404, 'not_found', 'Page was not found.');
        }
        return $this->render($response, 'table.twig', $this->pagination($data));
    }

    private function registryPage(ResponseInterface $response, array $registry, string $view, int $page, string $path): ResponseInterface
    {
        $offset = ($page - 1) * 50;
        $id = $registry['id'];
        $sql = match ($view) {
            'edit' => 'SELECT rr.reseller_id, u.username, rr.enabled FROM reseller_registries rr JOIN resellers u ON u.id = rr.reseller_id WHERE rr.registry_id = ? ORDER BY u.username',
            'tlds' => 'SELECT tld, enabled FROM registry_tlds WHERE registry_id = ? ORDER BY tld',
            'permissions' => 'SELECT rt.reseller_id, u.username, rt.tld, rt.enabled FROM reseller_tlds rt JOIN resellers u ON u.id = rt.reseller_id WHERE rt.registry_id = ? ORDER BY u.username, rt.tld',
        };
        $data = ['title' => match ($view) { 'edit' => 'Manage registry: ', 'tlds' => 'TLDs: ', 'permissions' => 'TLD permissions: ' } . $registry['name'],
            'section' => 'registries', 'registry' => $registry, 'view' => $view, 'page' => $page, 'path' => $path, 'paginate' => true,
            'rows' => PanelData::rows($this->pdo, $sql . " LIMIT 51 OFFSET {$offset}", [$id]),
            'profiles' => CommandCatalog::profiles(),
            'resellers' => PanelData::rows($this->pdo, 'SELECT id, username FROM resellers ORDER BY username LIMIT 500'),
            'tlds' => PanelData::rows($this->pdo, 'SELECT tld FROM registry_tlds WHERE registry_id = ? ORDER BY tld', [$id])];
        return $this->render($response, 'registry.twig', $this->pagination($data));
    }

    private function admin(ResponseInterface $response, string $section, int $page): ResponseInterface
    {
        $offset = ($page - 1) * 50;
        [$sql, $columns] = match ($section) {
            'resellers' => ['SELECT r.id AS account_id, r.username, r.display_name, r.company, r.email, r.role, r.status, r.currency, w.available_balance, w.held_balance FROM resellers r JOIN wallets w ON w.reseller_id = r.id ORDER BY r.username',
                ['account_id' => 'Reseller', 'display_name' => 'Name', 'company' => 'Company', 'email' => 'Email', 'role' => 'Role', 'status' => 'Status', 'currency' => 'Currency', 'available_balance' => 'Available', 'held_balance' => 'Held']],
            'registries' => ['SELECT id, name, profile, enabled FROM registries ORDER BY name', ['id' => 'UUID', 'name' => 'Registry', 'profile' => 'Profile', 'enabled' => 'Enabled']],
            'prices' => ['', []],
            'deposits' => ['SELECT d.id, r.username, d.amount, d.currency, d.status, d.client_reference, d.created_at, d.decided_at FROM deposit_requests d JOIN resellers r ON r.id = d.reseller_id ORDER BY d.created_at DESC, d.id DESC',
                ['id' => 'UUID', 'username' => 'Reseller', 'amount' => 'Amount', 'currency' => 'Currency', 'status' => 'Status', 'client_reference' => 'Reference', 'created_at' => 'Date', 'decided_at' => 'Decided']],
            'jobs' => ['SELECT j.id AS admin_job_id, r.username, g.name AS registry, j.command, j.status, j.price, j.currency, j.created_at FROM epp_jobs j JOIN resellers r ON r.id = j.reseller_id JOIN registries g ON g.id = j.registry_id ORDER BY j.created_at DESC, j.id DESC',
                ['admin_job_id' => 'UUID / details', 'username' => 'Reseller', 'registry' => 'Registry', 'command' => 'Command', 'status' => 'Status', 'price' => 'Price', 'currency' => 'Currency', 'created_at' => 'Date']],
            'ownership' => ['SELECT registry_id, reseller_id, object_type, object_name, active, domain_state, job_id FROM object_ownership ORDER BY registry_id, object_type, object_name',
                ['registry_id' => 'Registry UUID', 'reseller_id' => 'Reseller UUID', 'object_type' => 'Type', 'object_name' => 'Object', 'active' => 'Ownership confirmed', 'domain_state' => 'Domain state', 'job_id' => 'Pending job']],
        };
        $data = ['title' => 'Manage ' . $section, 'section' => $section, 'page' => $page, 'path' => '/admin/' . $section,
            'rows' => $section === 'prices' ? PanelData::adminPriceGroups($this->pdo, 51, $offset) : PanelData::rows($this->pdo, $sql . " LIMIT 51 OFFSET {$offset}"), 'columns' => $columns, 'paginate' => true,
            'resellers' => PanelData::rows($this->pdo, 'SELECT id, username FROM resellers ORDER BY username LIMIT 500'),
            'registries' => PanelData::rows($this->pdo, 'SELECT id, name FROM registries ORDER BY name LIMIT 500'),
            'profiles' => CommandCatalog::profiles(), 'commands' => array_column(CommandCatalog::all(), 'command')];
        if ($section === 'prices') {
            $data['price_operations'] = CommandCatalog::priceOperations();
            $data['price_zones'] = PanelData::rows($this->pdo, 'SELECT t.registry_id, t.tld, r.name AS registry FROM registry_tlds t JOIN registries r ON r.id = t.registry_id ORDER BY r.name, t.tld');
        }
        return $this->render($response, 'admin.twig', $this->pagination($data));
    }

    private function pagination(array $data): array
    {
        $data['next'] = ($data['paginate'] ?? false) && count($data['rows']) > 50;
        if ($data['next']) {
            array_pop($data['rows']);
        }
        return $data;
    }

    private function page(ServerRequestInterface $request): int
    {
        $value = $request->getQueryParams()['page'] ?? '1';
        return is_scalar($value) ? min(10000, max(1, (int) $value)) : 1;
    }

    private function render(ResponseInterface $response, string $template, array $data): ResponseInterface
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $this->view->render($response, $template, $data + ['user' => $this->user, 'csrf' => $_SESSION['csrf'] ?? '', 'flash' => $flash]);
    }

    private function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withHeader('Location', $path)->withStatus(303);
    }
}
