<?php

declare(strict_types=1);

namespace GardenLawn\Seo\Controller\Google;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Helper\Data as CatalogData;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;

class Feed implements HttpGetActionInterface
{
    private RawFactory $resultRawFactory;
    private CollectionFactory $productCollectionFactory;
    private StoreManagerInterface $storeManager;
    private ImageHelper $imageHelper;
    private CatalogData $catalogData;
    private State $appState;

    public function __construct(
        RawFactory $resultRawFactory,
        CollectionFactory $productCollectionFactory,
        StoreManagerInterface $storeManager,
        ImageHelper $imageHelper,
        CatalogData $catalogData,
        State $appState
    ) {
        $this->resultRawFactory = $resultRawFactory;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->storeManager = $storeManager;
        $this->imageHelper = $imageHelper;
        $this->catalogData = $catalogData;
        $this->appState = $appState;
    }

    public function execute(): ResultInterface
    {
        $this->appState->setAreaCode(Area::AREA_FRONTEND);

        $result = $this->resultRawFactory->create();
        $result->setHeader('Content-Type', 'text/xml');

        $xml = new \SimpleXMLElement('<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0"/>');
        $channel = $xml->addChild('channel');
        $channel->addChild('title', 'Product Feed');
        $channel->addChild('link', $this->storeManager->getStore()->getBaseUrl());
        $channel->addChild('description', 'Product feed for Google Merchant Center');

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect('*');
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->setVisibility(Visibility::VISIBILITY_BOTH);

        foreach ($collection as $product) {
            $item = $channel->addChild('item');
            $item->addChild('g:id', $product->getSku());
            $item->addChild('g:title', $product->getName());
            $item->addChild('g:description', strip_tags((string)$product->getShortDescription() ?: $product->getName()));
            $item->addChild('g:link', $product->getProductUrl());
            $item->addChild('g:image_link', $this->imageHelper->init($product, 'product_base_image')->getUrl());
            $item->addChild('g:availability', $product->isAvailable() ? 'in stock' : 'out of stock');

            $priceWithTax = $this->catalogData->getTaxPrice($product, $product->getFinalPrice(), true);
            $item->addChild('g:price', number_format((float)$priceWithTax, 2, '.', '') . ' ' . $this->storeManager->getStore()->getCurrentCurrencyCode());

            if ($brand = $product->getAttributeText('manufacturer')) {
                $item->addChild('g:brand', (string)$brand);
            } else {
                $item->addChild('g:brand', 'Brak');
            }

            $item->addChild('g:gtin', ''); // GTIN (EAN, UPC, JAN, ISBN)
            $item->addChild('g:mpn', $product->getSku()); // MPN (Manufacturer Part Number)
            $item->addChild('g:condition', 'new');
        }

        $result->setContents($xml->asXML());
        return $result;
    }
}
