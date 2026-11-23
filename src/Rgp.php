<?php

/*
 * Namingo Reseller
 *
 * Copyright (c) 2026 Terbora Ltd.
 *
 * Licensed under the PolyForm Noncommercial License 1.0.0.
 * Commercial use requires separate written authorization.
 * For licensing inquiries, contact hello@terbora.com.
 *
 * See the LICENSE file distributed with this software
 * for the full license terms.
 *
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 */

declare(strict_types=1);

namespace Namingo\ResellerApi;

use DateTimeImmutable;
use XMLWriter;

/** Safe RFC 3915 adapter: the pinned client's domainReport contains example data. */
final class Rgp
{
    public static function supported(string $profile): bool
    {
        return in_array($profile, ['generic', 'VRSN'], true);
    }

    public static function validate(array $params): void
    {
        foreach (['pre_data', 'post_data', 'deleted_at', 'restored_at', 'reason'] as $key) {
            if (!is_string($params[$key] ?? null) || trim($params[$key]) === '' || strlen($params[$key]) > 60000) {
                throw new ApiException(422, 'invalid_restore_report', "A nonempty {$key} is required (up to 60000 bytes).");
            }
        }
        foreach (['deleted_at', 'restored_at'] as $key) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $params[$key], new \DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $params[$key]) {
                throw new ApiException(422, 'invalid_restore_report', "{$key} must be a UTC date in YYYY-MM-DDTHH:MM:SSZ format.");
            }
        }
        if ($params['deleted_at'] > $params['restored_at']) {
            throw new ApiException(422, 'invalid_restore_report', 'Restore time cannot precede deletion time.');
        }
        $statements = $params['statements'] ?? null;
        if (!is_array($statements) || !array_is_list($statements) || count($statements) !== 2) {
            throw new ApiException(422, 'invalid_restore_report', 'Supply the two required registrar statements.');
        }
        foreach ([...$statements, $params['other'] ?? ''] as $index => $text) {
            if (!is_string($text) || ($index < 2 && trim($text) === '') || strlen($text) > 60000) {
                throw new ApiException(422, 'invalid_restore_report', 'Invalid registrar statement or supporting information.');
            }
        }
    }

    public static function reportXml(array $params): string
    {
        self::validate($params);
        $xml = self::start();
        $xml->startElement('update');
        $xml->startElementNS('domain', 'update', 'urn:ietf:params:xml:ns:domain-1.0');
        $xml->writeElement('domain:name', $params['domainname']);
        $xml->writeElement('domain:chg');
        $xml->endElement(); $xml->endElement();
        $xml->startElement('extension');
        $xml->startElementNS('rgp', 'update', 'urn:ietf:params:xml:ns:rgp-1.0');
        $xml->startElement('rgp:restore'); $xml->writeAttribute('op', 'report');
        $xml->startElement('rgp:report');
        foreach (['pre_data' => 'preData', 'post_data' => 'postData', 'deleted_at' => 'delTime',
            'restored_at' => 'resTime', 'reason' => 'resReason'] as $key => $element) {
            $xml->writeElement('rgp:' . $element, $params[$key]);
        }
        foreach ($params['statements'] as $statement) { $xml->writeElement('rgp:statement', $statement); }
        if (($params['other'] ?? '') !== '') { $xml->writeElement('rgp:other', $params['other']); }
        $xml->endElement(); $xml->endElement(); $xml->endElement(); $xml->endElement();
        return self::finish($xml);
    }

    public static function report(object $epp, array $params): array
    {
        return self::result($epp->writeRequest(self::reportXml($params)));
    }

    public static function info(object $epp, string $profile, array $params): array
    {
        if (!self::supported($profile)) {
            return Util::normalize($epp->domainInfo($params));
        }
        $xml = self::start(); $xml->startElement('info');
        $xml->startElementNS('domain', 'info', 'urn:ietf:params:xml:ns:domain-1.0');
        $xml->writeElement('domain:name', $params['domainname']);
        $xml->endElement(); $xml->endElement();
        $raw = $epp->writeRequest(self::finish($xml));
        $result = self::result($raw);
        if (($result['code'] ?? 0) !== 1000) { return $result; }
        $data = $raw->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
        $result += ['name' => (string) $data->name, 'clID' => (string) $data->clID, 'status' => [], 'rgp_status' => []];
        foreach ($data->status as $status) { $result['status'][] = (string) $status->attributes()->s; }
        if (isset($raw->response->extension)) {
            $rgp = $raw->response->extension->children('urn:ietf:params:xml:ns:rgp-1.0')->infData;
            foreach ($rgp->rgpStatus as $status) { $result['rgp_status'][] = (string) $status->attributes()->s; }
        }
        return $result;
    }

    private static function start(): XMLWriter
    {
        $xml = new XMLWriter(); $xml->openMemory(); $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNS(null, 'epp', 'urn:ietf:params:xml:ns:epp-1.0'); $xml->startElement('command');
        return $xml;
    }

    private static function finish(XMLWriter $xml): string
    {
        $xml->writeElement('clTRID', 'lifecycle-' . Util::uuid()); $xml->endElement(); $xml->endElement(); $xml->endDocument();
        return $xml->outputMemory();
    }

    private static function result(\SimpleXMLElement $raw): array
    {
        return ['code' => (int) $raw->response->result->attributes()->code, 'msg' => (string) $raw->response->result->msg];
    }
}
