<?php declare(strict_types=1);

namespace ExtractOcr;

use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Stdlib\Message;

/**
 * Extract OCR module for Omeka S.
 *
 * The features was merged into IiifSearch:
 * - Fresh installs are refused.
 * - When IiifSearch >= 3.4.15 is active, this module auto-uninstalls on boot
 *   so that admins do not have to do it manually. Settings have already been
 *   migrated by IiifSearch at its own boot.
 */
class Module extends AbstractModule
{
    public function getConfig()
    {
        return [
            'translator' => [
                'translation_file_patterns' => [
                    [
                        'type' => \Laminas\I18n\Translator\Loader\Gettext::class,
                        'base_dir' => __DIR__ . '/language',
                        'pattern' => '%s.mo',
                        'text_domain' => null,
                    ],
                ],
            ],
        ];
    }

    public function install(ServiceLocatorInterface $services): void
    {
        throw new ModuleCannotInstallException((string) new Message(
            'The module Extract OCR has been merged into IIIF Search. Install module IIIF Search 3.4.15 or later instead.', // @translate
        ));
    }

    public function uninstall(ServiceLocatorInterface $services): void
    {
        // No-op: settings were migrated by IiifSearch; do not delete files.
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services): void
    {
        // No-op: managed automatically.
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        $services = $this->getServiceLocator();
        $moduleManager = $services->get('Omeka\ModuleManager');

        $iiifSearch = $moduleManager->getModule('IiifSearch');
        if (!$iiifSearch
            || $iiifSearch->getState() !== \Omeka\Module\Manager::STATE_ACTIVE
        ) {
            return;
        }

        $version = (string) $iiifSearch->getIni('version');
        if (version_compare($version, '3.4.15', '<')) {
            return;
        }

        $self = $moduleManager->getModule('ExtractOcr');
        if (!$self) {
            return;
        }

        try {
            $moduleManager->deactivate($self);
            $moduleManager->uninstall($self);
            $services->get('Omeka\Logger')->notice((string) new Message(
                'Module Extract OCR auto-uninstalled: features are now in IIIF Search.' // @translate
            ));
        } catch (\Throwable $e) {
            // Silent: do not block boot if uninstall fails.
        }
    }
}
