<?php
namespace Mgenius\TailwindCompiler;

use Symfony\Component\Process\Process;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

class Builder
{
	const RELEASE = "https://api.github.com/repos/tailwindlabs/tailwindcss/releases/latest";
	const REPOSITIRY = "https://github.com/tailwindlabs/tailwindcss/releases/download/%s/%s";
	public static $version = null;
	public static $binary = null;

	public static function build(?string $html = null, ?string $configFile = null, ?string $version = null): string
	{
		$path = dirname(__DIR__) . '/src/workspace/';
		$lib = dirname(__DIR__) . '/bin/';

        self::write($path . 'i.html', $html);
        self::write($path . 'o.css', null);

		if(!$configFile && !file_exists($configFile))
			$configFile = dirname(__DIR__) . '/src/config.js';

		$cmd = [
            self::getCli($version),
            '--no-autoprefixer',
            '-c',
            $configFile,
            '-i',
            $path . 'i.css',
            '-o',
            $path . 'o.css',
            '--minify'
        ];

        $tailwindcss = new Process($cmd);
        $status = $tailwindcss->run();

        if ($status !== 0) {
            throw new \Exception(str_replace("\n", "\\A", $tailwindcss->getErrorOutput()));
        }
		return file_get_contents($path . 'o.css');
	}

    public static function getCli(?string $version = null): string
    {
        if(empty($version))
        $version = self::getLatestVersion();

        $lib = dirname(__DIR__) . '/bin/' . $version;

        $binaryName = self::getBinaryName();

        if (!file_exists($lib . '/' . $binaryName)) {
            self::downloadExecutable($version);
        }

        return $lib . '/' . $binaryName;
    }

	private static function getLatestVersion(): string
    {
        if(empty(self::$version)){
            try {
                $curl = HttpClient::create();
                $response = $curl->request('GET', self::RELEASE);

                if(isset($response->toArray()['name']))
                    self::$version = $response->toArray()['name'];
                else
                    throw new \Exception('Cannot get the latest version name from response JSON.');
            } catch (\Throwable $e) {
                throw new \Exception($e->getMessage());
            }
        }
        return self::$version;
    }

	private static function downloadExecutable(?string $version = null): void
    {
        if(empty($version))
            $version = self::getLatestVersion();

    	$binaryName = self::getBinaryName();
        $url = sprintf(self::REPOSITIRY, $version, $binaryName);

        $lib = dirname(__DIR__) . '/bin/' . $version;

        try {
            $curl = HttpClient::create();
            $response = $curl->request('GET', $url);
            $content = $response->getContent();
        }catch (HttpExceptionInterface $e)
        {
            throw new \InvalidArgumentException('Version is not exists:' . $e->getMessage());
        }

        //Create dir bin or version if not exists
        self::mkdir(dirname(__DIR__) . '/bin/');
        self::mkdir($lib);

        self::write($lib . '/' . $binaryName, $content);

        chmod($lib . '/' . $binaryName, 0777);
    }

	/**
     * @internal
     */
    public static function getBinaryName(): string
    {
        if(empty(self::$binary)){
            $os = strtolower(\PHP_OS);
            $machine = strtolower(php_uname('m'));

            switch (true) {
                case str_contains($os, 'darwin'):
                    switch ($machine) {
                        case 'arm64':
                            self::$binary = 'tailwindcss-macos-arm64';
                            break;
                        case 'x86_64':
                            self::$binary = 'tailwindcss-macos-x64';
                            break;
                        default:
                            throw new \Exception(sprintf('No matching machine found for Darwin platform (Machine: %s).', $machine));
                    }
                    break;
                case str_contains($os, 'linux'):
                    switch ($machine) {
                        case 'arm64':
                        case 'aarch64':
                            self::$binary = 'tailwindcss-linux-arm64';
                        break;
                        case 'armv7':
                            self::$binary = 'tailwindcss-linux-armv7';
                            break;
                        case 'x86_64':
                            self::$binary = 'tailwindcss-linux-x64';
                            break;
                        default:
                            throw new \Exception(sprintf('No matching machine found for Linux platform (Machine: %s).', $machine));
                    }
                    break;
                case str_contains($os, 'win'):
                    switch ($machine) {
                        case 'arm64':
                            self::$binary = 'tailwindcss-windows-arm64.exe';
                            break;
                        case 'x86_64':
                        case 'amd64':
                            self::$binary = 'tailwindcss-windows-x64.exe';
                            break;
                        default:
                            throw new \Exception(sprintf('No matching machine found for Windows platform (Machine: %s).', $machine));
                    }
                    break;
                default:
                    throw new \Exception(sprintf('Unknown platform or architecture (OS: %s, Machine: %s).', $os, $machine));
            }
        }
        return self::$binary;
    }

    private static function write($path, $content)
    {
        $result = file_put_contents($path, $content);
        if($result === false)
            throw new \Exception('Directory is not writable: ' . $path);
    }
    private static function mkdir($path)
    {
        if (!is_dir($path)) {
            $result = mkdir($path, 0777, true);
            if($result === false)
                throw new \Exception('Directory is not writable: ' . $lib);
        }
    }

    public static function postInstall(): void
    {
        self::downloadExecutable();
    }
    public static function postUpdate(): void
    {
        self::downloadExecutable();
    }
};