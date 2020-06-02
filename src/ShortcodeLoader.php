<?php

namespace Vendi\Shortcodes;

use Exception;
use Symfony\Component\Yaml\Yaml;
use Webmozart\PathUtil\Path;
use function add_shortcode;
use function delete_transient;
use function get_template_directory;
use function get_transient;
use function set_transient;
use function untrailingslashit;
use function wp_cache_delete;
use function wp_cache_get;
use function wp_cache_set;

final class ShortcodeLoader
{
    private $theme_dir_abs;

    public const CACHE_KEY = 'shortcode-config';
    public const TRANSIENT_KEY = 'vendi-shortcode-config';

    public const FETCHER_CACHE = 'FETCHER_CACHE';
    public const FETCHER_TRANSIENT = 'FETCHER_TRANSIENT';
    public const FETCHER_YAML = 'FETCHER_YAML';

    public function get_env(string $name): string
    {
        $ret = getenv($name);
        if (false === $ret) {
            return '';
        }
        return $ret;
    }

    public function get_theme_dir(): string
    {
        if (!$this->theme_dir_abs) {
            $this->theme_dir_abs = untrailingslashit(get_template_directory());
        }

        return $this->theme_dir_abs;
    }

    public function get_config_file(): string
    {
        $file = $this->get_env('SHORTCODE_YAML_FILE');

        if ($file) {
            if (is_file($file)) {
                return $file;
            }

            //makeAbsolute doesn't work against streams, apparently
            return Path::makeAbsolute($file, $this->get_theme_dir());
        }

        //This is the default
        return Path::join($this->get_theme_dir() . '/.config/shortcodes.yaml');
    }

    public function is_config_valid($config): bool
    {
        if (!is_array($config)) {
            return false;
        }

        if (!array_key_exists('shortcodes', $config)) {
            return false;
        }

        return true;
    }

    public function get_config(): array
    {
        $fetchers = [
            // Check fast cache first
            self::FETCHER_CACHE => static function () {
                return wp_cache_get(self::CACHE_KEY);
            },

            // Then check transient cache
            // self::FETCHER_TRANSIENT => static function () {
            //     return get_transient(self::TRANSIENT_KEY);
            // },

            // Lastly, check the file system
            self::FETCHER_YAML => function () {
                return Yaml::parseFile($this->get_config_file());
            },
        ];

        $ret = false;
        $used_fetcher = null;

        /* @var callable $fetcher */
        foreach ($fetchers as $key => $fetcher) {
            try {
                $maybeRet = $fetcher();
            } catch (Exception $ex) {
                // If a fetcher fails, it shouldn't be a fatal problem. The only one that would
                // probably ever fail would be the YAML file anyway.
                continue;
            }

            if ($this->is_config_valid($maybeRet)) {
                $used_fetcher = $key;
                $ret = $maybeRet;
                break;
            }
        }

        if (!$ret || !is_array($ret)) {

            // Purge all potential caches of potentially invalid data
            wp_cache_delete(self::CACHE_KEY);
            delete_transient(self::TRANSIENT_KEY);

            // If all fetchers fail, we still want to return something
            return [];
        }

        switch ($used_fetcher) {

            // This could just pass through but I prefer to be explicit
            case self::FETCHER_YAML:
                set_transient(self::TRANSIENT_KEY, $ret, 0);
                wp_cache_set(self::CACHE_KEY, $ret);
                break;

            case self::FETCHER_TRANSIENT:
                wp_cache_set(self::CACHE_KEY, $ret);
        }

        return $ret;
    }

    public function create_objects(): array
    {
        $ret = [];
        $config = $this->get_config();

        // Optional shared namespace for shortcodes
        $namespace = '';
        if (array_key_exists('namespace', $config)) {
            $namespace = $config['namespace'];
        }

        foreach ($config['shortcodes'] as $shortcode => $func) {

            // Try to create a classname that we can invoke
            $callableClass = null;
            if (class_exists($func)) {
                $callableClass = $func;
            } elseif (class_exists($namespace . '\\' . $func)) {
                $callableClass = $namespace . '\\' . $func;
            }

            // Try to find a callable function in that class
            $callable = null;
            if ($callableClass) {

                // The preferred method to use is get_html() however also support __invoke
                if (method_exists($callableClass, 'get_html')) {
                    $callable = [$callableClass, 'get_html'];
                } elseif (method_exists($callableClass, '__invoke')) {
                    $callable = [$callableClass, '__invoke'];
                }
            }

            // If we didn't create a callable function above, create one here and output an error
            if (!$callable) {
                $callable = static function () use ($shortcode) {
                    return sprintf('Could not find a handler for the shortcode [%1$s]', esc_html($shortcode));
                };
            }

            $ret[$shortcode] = $callable;
        }

        return $ret;
    }

    public static function register_all(): void
    {
        $me = new self();
        $objects = $me->create_objects();
        foreach ($objects as $key => $func) {
            add_shortcode($key, $func);
        }
    }
}
