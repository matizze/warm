<?php

namespace App\Support\Media;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Downloads a media file from an https URL with the protections the MCP tool
 * needs: https only, DNS-resolved SSRF guard (including on every redirect),
 * bounded redirects, connection/download timeouts and a hard size cap that
 * aborts the stream as soon as it is exceeded.
 */
final class RemoteMediaFetcher
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(private readonly HostResolver $resolver) {}

    public function fetch(string $url, string $type): FetchedMedia
    {
        $maxBytes = MediaType::maxBytes($type);

        if ($maxBytes <= 0) {
            throw new InvalidArgumentException('Tipo de mídia inválido para download.');
        }

        $current = $url;
        $maxRedirects = (int) config('warm.media.download.max_redirects', 3);

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $this->assertAllowedUrl($current);

            $response = $this->request($current);
            $status = $response->status();

            if (in_array($status, self::REDIRECT_STATUSES, true)) {
                $location = trim((string) $response->header('Location'));
                $this->close($response);

                if ($location === '') {
                    throw new RuntimeException('A URL redirecionou sem informar o destino.');
                }

                $current = (string) UriResolver::resolve(new Uri($current), new Uri($location));

                continue;
            }

            if ($status < 200 || $status >= 300) {
                $this->close($response);

                throw new RuntimeException("Falha ao baixar a mídia: HTTP {$status}.");
            }

            $declaredLength = $response->header('Content-Length');
            if (is_numeric($declaredLength) && (int) $declaredLength > $maxBytes) {
                $this->close($response);

                throw $this->tooLarge($type, $maxBytes);
            }

            $contents = $this->read($response->toPsrResponse()->getBody(), $type, $maxBytes);

            if ($contents === '') {
                throw new RuntimeException('A URL não retornou conteúdo.');
            }

            $contentType = trim((string) $response->header('Content-Type'));

            return new FetchedMedia(
                contents: $contents,
                mimeType: $contentType,
                filename: $this->filename($response, $current),
            );
        }

        throw new RuntimeException('A URL redirecionou vezes demais.');
    }

    private function request(string $url): Response
    {
        try {
            return Http::withOptions([
                'allow_redirects' => false,
                'stream' => true,
                'http_errors' => false,
                'connect_timeout' => (int) config('warm.media.download.connect_timeout', 10),
                'timeout' => (int) config('warm.media.download.timeout', 120),
            ])->withHeaders(['Accept' => '*/*'])->get($url);
        } catch (ConnectionException|GuzzleException $e) {
            throw new RuntimeException('Não foi possível conectar à URL da mídia: '.$e->getMessage(), 0, $e);
        }
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('URL inválida: informe uma URL https válida.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new InvalidArgumentException('URL inválida: apenas https é aceito.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('URL inválida: não envie credenciais na URL.');
        }

        $port = $parts['port'] ?? 443;
        if ($port !== 443) {
            throw new InvalidArgumentException('URL inválida: apenas a porta 443 é aceita.');
        }

        $addresses = $this->resolver->resolve($parts['host']);

        if ($addresses === []) {
            throw new InvalidArgumentException('URL inválida: o host não pôde ser resolvido.');
        }

        foreach ($addresses as $address) {
            if (BlockedAddress::isBlocked($address)) {
                throw new InvalidArgumentException(
                    'URL bloqueada: o destino aponta para rede privada, loopback ou metadados de nuvem.'
                );
            }
        }
    }

    private function read(StreamInterface $body, string $type, int $maxBytes): string
    {
        $contents = '';

        try {
            while (! $body->eof()) {
                $chunk = $body->read(8192);

                if ($chunk === '') {
                    break;
                }

                $contents .= $chunk;

                if (strlen($contents) > $maxBytes) {
                    throw $this->tooLarge($type, $maxBytes);
                }
            }
        } finally {
            $body->close();
        }

        return $contents;
    }

    private function close(Response $response): void
    {
        $response->toPsrResponse()->getBody()->close();
    }

    private function filename(Response $response, string $url): ?string
    {
        $disposition = (string) $response->header('Content-Disposition');

        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $matches) === 1) {
            $name = basename(trim($matches[1]));

            if ($name !== '') {
                return $name;
            }
        }

        $path = parse_url($url, PHP_URL_PATH);
        $name = is_string($path) ? basename($path) : '';

        return $name !== '' ? $name : null;
    }

    private function tooLarge(string $type, int $maxBytes): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'Arquivo acima do limite para %s: máximo %s.',
            $type,
            MediaType::humanSize($maxBytes),
        ));
    }
}
