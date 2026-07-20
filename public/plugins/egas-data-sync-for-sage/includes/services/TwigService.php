<?php

declare(strict_types=1);

namespace Egas\services;

use Egas\Sage;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;

class TwigService
{
    private static ?TwigService $twigService = null;
    public Environment $twig;
    public string $dir;
    public bool $register = false;

    private function __construct()
    {
        $templatesDir = __DIR__ . '/../../templates';
        $filesystemLoader = new FilesystemLoader($templatesDir);
        $twigOptions = [
            'debug' => WP_DEBUG,
        ];
        if (!WP_DEBUG) {
            $twigOptions['cache'] = $templatesDir . '/cache';
        }

        $this->twig = new Environment($filesystemLoader, $twigOptions);
        if (WP_DEBUG) {
            // https://twig.symfony.com/doc/3.x/functions/dump.html
            $this->twig->addExtension(new DebugExtension());
        }
        $this->dir = dirname((string)Sage::getInstance()->file);
//        $this->twig->addExtension(new IntlExtension());
    }

    public static function getInstance(): self
    {
        if (self::$twigService === null) {
            self::$twigService = new self();
        }
        return self::$twigService;
    }

    public function render(string $name, array $context = []): string
    {
        return $this->twig->render($name, $context);
    }
}
