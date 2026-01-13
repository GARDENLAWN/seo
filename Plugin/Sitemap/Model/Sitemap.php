<?php

namespace GardenLawn\Seo\Plugin\Sitemap\Model;

use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\DriverPool;

class Sitemap
{
    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(
        Filesystem $filesystem
    ) {
        $this->filesystem = $filesystem;
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
        $this->setLocalDirectory($subject);
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
        $this->setLocalDirectory($subject);
        return [];
    }

    /**
     * Set local directory driver
     *
     * @param \Magento\Sitemap\Model\Sitemap $subject
     */
    private function setLocalDirectory($subject)
    {
        // Use reflection to set the directory write instance to local file driver
        // This bypasses S3 or other remote storage drivers configured globally

        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::PUB, DriverPool::FILE);

        $reflection = new \ReflectionClass($subject);
        $property = $reflection->getProperty('_directory');
        $property->setAccessible(true);
        $property->setValue($subject, $directory);
    }
}
