<?php

declare(strict_types=1);

namespace Namingo\ResellerApi;

use Nyholm\Psr7\Stream;
use Utopia\Storage\Device\Local;

final class InvoiceStorage
{
    private readonly Local $device;
    public function __construct(bool $create = false)
    {
        $root = rtrim(Config::get('DATA_DIRECTORY', '/var/lib/namingo-api/data'), '/') . '/invoices';
        if ($root[0] !== '/' || str_contains($root, '/../') || is_link($root)) { throw new \RuntimeException('Invoice storage must be a private absolute directory.'); }
        if ($create && !is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) { throw new \RuntimeException('Cannot create invoice storage.'); }
        $real = realpath($root); $public = realpath(dirname(__DIR__) . '/panel/public');
        if ($real === false || $real !== $root || ($public !== false && ($real === $public || str_starts_with($real, $public . '/')))
            || (fileperms($real) & 0027) !== 0) { throw new \RuntimeException('Invoice storage must exist outside the web root with private permissions.'); }
        $this->device = new Local($real);
    }
    private function path(string $id): string
    {
        if (!Util::isUuid($id)) { throw new \RuntimeException('Invalid invoice storage identifier.'); }
        $path = $this->device->getPath($id . '.enc');
        if (is_link($path)) { throw new \RuntimeException('Invoice files cannot be symlinks.'); }
        return $path;
    }
    public function write(string $id, string $pdf): void
    {
        [$nonce, $cipher] = Crypto::encrypt($pdf, 'invoice-pdf:' . $id);
        $old = umask(0027);
        try { $this->device->write($this->path($id), Stream::create('NINV1' . $nonce . $cipher), 'application/octet-stream'); }
        finally { umask($old); }
    }
    public function read(array $invoice): string
    {
        $encrypted = $this->device->read($this->path($invoice['id']))->getContents();
        if (!str_starts_with($encrypted, 'NINV1') || strlen($encrypted) < 45) { throw new \RuntimeException('Invalid encrypted invoice.'); }
        $pdf = Crypto::decrypt(substr($encrypted, 5, 24), substr($encrypted, 29), 'invoice-pdf:' . $invoice['id']);
        if (!str_starts_with($pdf, '%PDF-') || !hash_equals($invoice['pdf_hash'] ?? '', hash('sha256', $pdf))) { throw new \RuntimeException('Invoice integrity verification failed.'); }
        return $pdf;
    }
}
