<?php
/**
 * Silverstripe Typesense module
 * @license LGPL3 With Attribution
 * @copyright Copyright (C) 2024 Elliot Sawyer
 */

namespace ElliotSawyer\SilverstripeTypesense;

use Exception;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Manifest\Module;
use SilverStripe\Core\Manifest\ModuleLoader;
use Typesense\Client;

final class Typesense extends Controller
{
    private static $allowed_actions = [
        'license',
        'attribution_notice',
    ];

    private static $collections = [];
    private static $connection_timeout = 2;

    public static function client($connection_timeout = 0)
    {
        if (!$connection_timeout) {
            $connection_timeout = self::config()->connection_timeout;
        }
        $server = self::parse_typesense_server();
        if (!$server) {
            throw new Exception(
                _t(self::class . '.EXCEPTION_schemeformat', 'TYPESENSE_SERVER must be in scheme://host:port format')
            );
        }

        $localhost = $server['host'] ?? 'localhost';
        $localport = $server['port'] ?? 8081;
        $localpath = $server['path'] ?? '';
        $localprotocol = $server['scheme'] ?? 'http';
        if (!isset($server['port'])) {
            $localport = $localprotocol == 'https'
                ? 443
                : 80;
        }

        $nodes = [];
        if ($localhost && $localport && $localprotocol) {
            $nodes[] = [
                'host' => $localhost,
                'port' => $localport,
                'protocol' => $localprotocol,
                'path' => $localpath,
            ];
        }

        return new Client([
            'api_key' => Environment::getEnv('TYPESENSE_API_KEY'),
            'nodes' => $nodes,
            'connection_timeout' => (int)$connection_timeout,
        ]);
    }

    public static function parse_typesense_server(): array
    {
        $server = Environment::getEnv('TYPESENSE_SERVER') ?? '';
        $parts = parse_url($server);
        if (!is_array($parts)) {
            return [];
        }

        return isset($parts['scheme'], $parts['host']) ? $parts : [];
    }

    public function license(HTTPRequest $request): HTTPResponse
    {
        $licenseContent = 'Unable to load license';
        $manifest = ModuleLoader::inst()->getManifest();
        $module = null;
        if ($manifest) {
            $module = $manifest->getModule('moritz-sauer-13/silverstripe-typesense-instantsearch')
                ?: $manifest->getModule('elliotsawyer/silverstripe-typesense');
        }
        if ($module instanceof Module) {
            $path = realpath($module->getPath());
            $license = $path . DIRECTORY_SEPARATOR . 'LICENSE.md';
            if (is_readable($license)) {
                $licenseContent = file_get_contents($license);
                if (!preg_match('/Copyright \(C\) \d{4} Elliot Sawyer/', $licenseContent)) {
                    $licenseContent = "Copyright statement altered";
                    $this->httpError(451, $licenseContent);
                }
            } else {
                $licenseContent = "LICENSE.md not readable";
                $this->httpError(451, $licenseContent);
            }
        } else {
            $licenseContent = "Module not readable";
            $this->httpError(451, $licenseContent);
        }
        $this->attribution_notice($request);
        return $this->getResponse()->setBody($licenseContent);
    }

    public function attribution_notice(HTTPRequest $request = null): HTTPResponse
    {
        $attribution_notice = $this->CopyrightStatement();
        if ($request && $request instanceof HTTPRequest) {
            $this->getResponse()->addHeader('Content-Type', 'text/plain');
            $this->getResponse()->addHeader('X-Typesense-License', $attribution_notice);
        }
        return $this->getResponse()->setBody($attribution_notice);
    }

    public function CopyrightStatement(): string
    {
        return "This software includes contributions from Elliot Sawyer, available under the LGPL v3.0 license.";
    }
}
