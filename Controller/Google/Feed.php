<?php

declare(strict_types=1);

namespace GardenLawn\Seo\Controller\Google;

use DOMDocument;
use DOMElement;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Helper\Data as CatalogData;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use GardenLawn\GoogleAds\Model\ResourceModel\ProductSegmentation as SegmentationResource;
use GardenLawn\GoogleAds\Model\ProductSegmentationFactory;

class Feed implements HttpGetActionInterface
{
    private RawFactory $resultRawFactory;
    private CollectionFactory $productCollectionFactory;
    private StoreManagerInterface $storeManager;
    private ImageHelper $imageHelper;
    private CatalogData $catalogData;
    private State $appState;
    private SegmentationResource $segmentationResource;
    private ProductSegmentationFactory $segmentationFactory;

    public function __construct(
        RawFactory $resultRawFactory,
        CollectionFactory $productCollectionFactory,
        StoreManagerInterface $storeManager,
        ImageHelper $imageHelper,
        CatalogData $catalogData,
        State $appState,
        SegmentationResource $segmentationResource,
        ProductSegmentationFactory $segmentationFactory
    ) {
        $this->resultRawFactory = $resultRawFactory;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->storeManager = $storeManager;
        $this->imageHelper = $imageHelper;
        $this->catalogData = $catalogData;
        $this->appState = $appState;
        $this->segmentationResource = $segmentationResource;
        $this->segmentationFactory = $segmentationFactory;
    }

    /**
     * @throws NoSuchEntityException
     * @throws \DOMException
     */
    public function execute(): ResultInterface
    {
        // Clear output buffer to prevent whitespace before XML declaration
        if (ob_get_level()) {
            ob_end_clean();
        }

        try {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        } catch (LocalizedException $e) {
            // Area code already set
        }

        $result = $this->resultRawFactory->create();
        $result->setHeader('Content-Type', 'application/xml; charset=utf-8');

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $rss = $dom->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:g', 'http://base.google.com/ns/1.0');
        $dom->appendChild($rss);

        $channel = $dom->createElement('channel');
        $rss->appendChild($channel);

        $channel->appendChild($dom->createElement('title', 'Product Feed'));
        $channel->appendChild($dom->createElement('link', $this->storeManager->getStore()->getBaseUrl()));
        $channel->appendChild($dom->createElement('description', 'Product feed for Google Merchant Center'));

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect('*');
        $collection->addWebsiteFilter();
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->setVisibility(Visibility::VISIBILITY_BOTH);

        $collection->addFinalPrice()->addAttributeToFilter('price', ['gt' => 0]);

        // Preload segmentation data to avoid N+1 queries
        // In a real scenario, we would join the table, but for simplicity we'll load on demand or cache
        // TODO: Join gardenlawn_googleads_product_segmentation table

        foreach ($collection as $product) {
            $item = $dom->createElement('item');
            $channel->appendChild($item);

            $this->addNode($dom, $item, 'g:id', $product->getSku());
            $this->addNode($dom, $item, 'g:title', $product->getName());

            $description = $product->getData('meta_description');
            if (empty($description)) {
                $description = $product->getData('short_description');
            }
            if (empty($description)) {
                $description = $product->getName();
            }
            $this->addNode($dom, $item, 'g:description', strip_tags((string)$description));

            $this->addNode($dom, $item, 'g:link', $product->getProductUrl());
            $this->addNode($dom, $item, 'g:image_link', $this->imageHelper->init($product, 'product_base_image')->getUrl());
            $this->addNode($dom, $item, 'g:availability', 'in stock');

            $priceWithTax = $this->catalogData->getTaxPrice($product, $product->getFinalPrice(), true);
            $this->addNode($dom, $item, 'g:price', number_format((float)$priceWithTax, 2, '.', '') . ' ' . $this->storeManager->getStore()->getCurrentCurrencyCode());

            if ($brand = $product->getAttributeText('manufacturer')) {
                $this->addNode($dom, $item, 'g:brand', (string)$brand);
            } else {
                $this->addNode($dom, $item, 'g:brand', 'Garden Lawn');
            }

            if ($gtin = $product->getData('GTIN13')) {
                $this->addNode($dom, $item, 'g:gtin', (string)$gtin);
            }

            $this->addNode($dom, $item, 'g:mpn', $product->getSku());
            $this->addNode($dom, $item, 'g:condition', 'new');

            // --- Custom Labels (Marża / Segmentacja) ---
            
            // 1. Segmentacja (STAR, ZOMBIE, POTENTIAL)
            $segment = $this->getProductSegment((int)$product->getId());
            if ($segment) {
                $this->addNode($dom, $item, 'g:custom_label_0', $segment); // Label 0: Performance Segment
            }

            // 2. Marża (High/Low)
            $marginLabel = $this->getMarginLabel($product);
            $this->addNode($dom, $item, 'g:custom_label_1', $marginLabel); // Label 1: Margin Level
        }

        $result->setContents(rtrim($dom->saveXML()));
        return $result;
    }

    private function addNode(DOMDocument $dom, DOMElement $parent, string $name, string $value): void
    {
        // Clean string
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        $element = $dom->createElement($name);
        $element->appendChild($dom->createTextNode($value));
        $parent->appendChild($element);
    }

    private function getProductSegment(int $productId): ?string
    {
        try {
            $segmentation = $this->segmentationFactory->create();
            $this->segmentationResource->load($segmentation, $productId, 'product_id');
            return $segmentation->getSegmentLabel();
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getMarginLabel($product): string
    {
        $cost = (float)$product->getData('cost');
        $price = (float)$product->getPrice();

        if ($price <= 0 || $cost <= 0) {
            return 'Unknown Margin';
        }

        $margin = ($price - $cost) / $price; // Gross Margin %

        if ($margin >= 0.40) {
            return 'High Margin (>40%)';
        } elseif ($margin >= 0.20) {
            return 'Medium Margin (20-40%)';
        } else {
            return 'Low Margin (<20%)';
        }
    }
}
