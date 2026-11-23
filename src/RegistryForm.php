<?php

/*
 * Namingo Reseller
 *
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the PolyForm Noncommercial License 1.0.0.
 * Commercial use requires separate written authorization.
 * For licensing inquiries, contact help@namingo.org.
 *
 * See the LICENSE file distributed with this software
 * for the full license terms.
 *
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 */

declare(strict_types=1);

namespace Namingo\ResellerApi;

/** Panel adapter only; storage and EPP configuration keep their existing JSON shape. */
final class RegistryForm
{
    private const OBJECTS = ['urn:ietf:params:xml:ns:domain-1.0','urn:ietf:params:xml:ns:contact-1.0','urn:ietf:params:xml:ns:host-1.0'];
    private const EXTENSIONS = ['urn:ietf:params:xml:ns:secDNS-1.1','urn:ietf:params:xml:ns:rgp-1.0'];

    public static function fields(?array $config = null): array
    {
        $connection = $config['connection'] ?? []; $login = $config['login'] ?? [];
        $debug = !($connection['verify_peer'] ?? true) || !($connection['verify_peer_name'] ?? true) || ($connection['allow_self_signed'] ?? false);
        return ['epp_host'=>$connection['host'] ?? '', 'epp_port'=>$connection['port'] ?? 700,
            'epp_username'=>$login['clID'] ?? '', 'epp_password'=>$login['pw'] ?? '', 'registry_debug'=>$debug ? '1' : '0',
            'ca_pem'=>$connection['ca_pem'] ?? '', 'client_cert_pem'=>$connection['client_cert_pem'] ?? '',
            'client_key_pem'=>$connection['client_key_pem'] ?? '', 'client_key_passphrase'=>$connection['client_key_passphrase'] ?? $connection['passphrase'] ?? '',
            'epp_prefix'=>$login['prefix'] ?? 'api', 'epp_timeout'=>$connection['timeout'] ?? 30, 'epp_tls'=>$connection['tls'] ?? '1.3',
            'login_objects'=>implode(",\n",$config === null ? self::OBJECTS : ($config['loginObjects'] ?? [])),
            'login_extensions'=>implode(",\n",$config === null ? self::EXTENSIONS : ($config['loginExtensions'] ?? [])),
            'bind_ip'=>!empty($connection['bind']) ? self::bindAddress((string)($connection['bindip'] ?? '')) : ''];
    }

    private static function text(array $input, string $field, bool $trim = true): string
    {
        $value = $input[$field] ?? '';
        if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || strlen($value)>2_000_000) {
            throw new ApiException(422,'invalid_registry_field','Invalid registry field: '.$field.'.');
        }
        return $trim ? trim($value) : $value;
    }

    /** Accept raw IPs or an existing socket address; the form uses an ephemeral source port. */
    private static function bindAddress(string $value): string
    {
        if (preg_match('/^\[([^\]]+)\](?::[0-9]+)?$/D',$value,$match) === 1) { return $match[1]; }
        if (preg_match('/^([^:]+):[0-9]+$/D',$value,$match) === 1) { return $match[1]; }
        return $value;
    }

    /** Generate a draft only: no database or filesystem writes. */
    public static function generateSsl(array $input): array
    {
        if (self::text($input,'registry_debug') !== '1') {
            throw new ApiException(422,'ssl_debug_required','Enable Debug before generating SSL.');
        }
        foreach (['ca_pem','client_cert_pem','client_key_pem'] as $field) {
            if (self::text($input,$field) !== '') {
                throw new ApiException(409,'ssl_fields_not_empty','Clear the SSL fields before generating a new pair.');
            }
        }
        $passphrase = self::text($input,'client_key_passphrase',false);
        if (strlen($passphrase)>1024 || str_contains($passphrase,"\0")) {
            throw new ApiException(422,'invalid_ssl_passphrase','The client key passphrase is invalid.');
        }
        $options = ['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048,'digest_alg'=>'sha256'];
        $key = openssl_pkey_new($options);
        if ($key === false) { throw new ApiException(503,'ssl_generation_failed','SSL generation failed. Try again.'); }
        $csr = openssl_csr_new(['commonName'=>'Namingo EPP client'],$key,$options);
        $certificate = $csr === false ? false : openssl_csr_sign($csr,null,$key,365,$options,random_int(1,PHP_INT_MAX));
        if ($certificate === false || !openssl_x509_export($certificate,$certificatePem)
            || !openssl_pkey_export($key,$privateKeyPem,$passphrase === '' ? null : $passphrase,$options)) {
            throw new ApiException(503,'ssl_generation_failed','SSL generation failed. Try again.');
        }
        return ['client_cert_pem'=>$certificatePem,'client_key_pem'=>$privateKeyPem];
    }

    public static function config(string $profile, array $input, array $existing = []): array
    {
        $debug = self::text($input,'registry_debug');
        if (!in_array($debug,['0','1'],true)) { throw new ApiException(422,'invalid_registry_debug','Choose Debug off or on.'); }
        $connection = $existing['connection'] ?? []; $login = $existing['login'] ?? [];
        $connection = array_replace($connection,['host'=>self::text($input,'epp_host'),'port'=>self::text($input,'epp_port'),
            'timeout'=>self::text($input,'epp_timeout'),'tls'=>self::text($input,'epp_tls'),
            'verify_peer'=>$debug==='0','verify_peer_name'=>$debug==='0','allow_self_signed'=>$debug==='1',
            'client_key_passphrase'=>self::text($input,'client_key_passphrase',false)]);
        foreach (['ca_pem','client_cert_pem','client_key_pem'] as $key) {
            $pem = str_replace(["\r\n","\r"],"\n",self::text($input,$key));
            $connection[$key] = $pem === '' ? null : $pem;
        }
        $bind = self::bindAddress(self::text($input,'bind_ip'));
        if ($bind !== '' && filter_var($bind,FILTER_VALIDATE_IP) === false) {
            throw new ApiException(422,'invalid_registry_bind','Enter a valid IPv4 or IPv6 bind address.');
        }
        $connection['bind'] = $bind !== '';
        $connection['bindip'] = $bind === '' ? '' : (str_contains($bind,':') ? '['.$bind.']:0' : $bind.':0');
        $login = array_replace($login,['clID'=>self::text($input,'epp_username'),'pw'=>self::text($input,'epp_password',false),'prefix'=>self::text($input,'epp_prefix')]);
        $config = array_replace($existing,['connection'=>$connection,'login'=>$login]);
        foreach (['loginObjects'=>'login_objects','loginExtensions'=>'login_extensions'] as $key=>$field) {
            $value = self::text($input,$field);
            // Missing/null overrides on an existing registry keep the client's profile defaults.
            if ($existing !== [] && !isset($existing[$key]) && $value === '') { continue; }
            $config[$key] = array_values(array_filter(array_map('trim',preg_split('/[,\r\n]+/',$value)),static fn($v)=>$v!==''));
        }
        try { return EppWorker::validateRegistryConfig($profile,$config); }
        catch (\RuntimeException $e) { throw new ApiException(422,'invalid_registry_config',$e->getMessage()); }
    }
}
