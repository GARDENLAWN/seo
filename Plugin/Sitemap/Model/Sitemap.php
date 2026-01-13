<?php

namespace GardenLawn\Seo\Plugin\Sitemap\Model;

use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\DriverPool;
use Psr\Log\LoggerInterface;

class Sitemap
{
    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Filesystem $filesystem
     * @param LoggerInterface $logger
     */
    public function __construct(
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->filesystem = $filesystem;
        $this->logger = $logger;
    }

    /**
     * Force local filesystem for sitemap generation
     *
     * @param \Magento\Sitemap\Model\Sitemap $subject
     * @return array
     */
    public function beforeGenerateXml(
        \Magento\Sitemap\Model\Sitemap $subject
    ) {
        $this->logger->info('GardenLawn_Seo: beforeGenerateXml started');
        $this->setLocalDirectories($subject);
        return [];
    }

    /**
     * Force local filesystem before saving (validation)
     *
     * @param \Magento\Sitemap\Model\Sitemap $subject
     * @return array
     */
    public function beforeSave(
        \Magento\Sitemap\Model\Sitemap $subject
    ) {
        $this->logger->info('GardenLawn_Seo: beforeSave started');
        $this->setLocalDirectories($subject);
        return [];
    }

    /**
     * Set local directory drivers
     *
     * @param \Magento\Sitemap\Model\Sitemap $subject
     */
    private function setLocalDirectories($subject)
    {
        try {
            // Force _directory to be local PUB
            $pubDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::PUB, DriverPool::FILE);

            // Use the specific class name to access private properties
            // This is crucial because $subject is an Interceptor and private properties
            // of the parent class are not visible via reflection on the Interceptor instance/class directly
            $reflection = new \ReflectionClass(\Magento\Sitemap\Model\Sitemap::class);

            $directoryProperty = $reflection->getProperty('_directory');
            $directoryProperty->setAccessible(true);
            $directoryProperty->setValue($subject, $pubDirectory);

            // Force tmpDirectory to be local SYS_TMP
            $tmpDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::SYS_TMP, DriverPool::FILE);

            $tmpProperty = $reflection->getProperty('tmpDirectory');
            $tmpProperty->setAccessible(true);
            $tmpProperty->setValue($subject, $tmpDirectory);

            $this->logger->info('GardenLawn_Seo: Directories forced to local driver. Pub path: ' . $pubDirectory->getAbsolutePath());

        } catch (\Exception $e) {
            $this->logger->error('GardenLawn_Seo: Error setting local directories: ' . $e->getMessage());
        }
    }
}
