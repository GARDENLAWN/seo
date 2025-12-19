<?php

declare(strict_types=1);

namespace GardenLawn\Seo\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\Registry;
use Magento\Catalog\Model\Product;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Helper\Data as CatalogData;
use Magento\Framework\Serialize\Serializer\Json;

class ProductSchema implements ArgumentInterface
{
    private Registry $registry;
    private StoreManagerInterface $storeManager;
    private ImageHelper $imageHelper;
    private Json $jsonSerializer;
    private CatalogData $catalogData;

    public function __construct(
        Registry $registry,
        StoreManagerInterface $storeManager,
        ImageHelper $imageHelper,
        Json $jsonSerializer,
        CatalogData $catalogData
    ) {
        $this->registry = $registry;
        $this->storeManager = $storeManager;
        $this->imageHelper = $imageHelper;
        $this->jsonSerializer = $jsonSerializer;
        $this->catalogData = $catalogData;
    }

    public function getProduct(): ?Product
    {
        return $this->registry->registry('current_product');
    }

    public function getJsonSchemaData(): ?string
    {
        $product = $this->getProduct();
        if (!$product) {
            return null;
        }

        $store = $this->storeManager->getStore();
        $currencyCode = $store->getCurrentCurrencyCode();

        // Get image URL
        $imageUrl = $this->imageHelper->init($product, 'product_page_image_medium')
            ->setImageFile($product->getImage())
            ->getUrl();

        // Get description logic: Meta Description -> Short Description -> Name
        $description = $product->getData('meta_description');
        if (empty($description)) {
            $description = $product->getData('short_description');
        }
        if (empty($description)) {
            $description = $product->getName();
        }

        // Clean up description
        $description = strip_tags((string)$description);
        $description = trim(preg_replace('/\s+/', ' ', $description));

        // Calculate Final Price Including Tax
        $finalPrice = $product->getFinalPrice();
        $priceWithTax = $this->catalogData->getTaxPrice($product, $finalPrice, true);

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->getName(),
            'description' => $description,
            'sku' => $product->getSku(),
            'image' => $imageUrl,
            'offers' => [
                '@type' => 'Offer',
                'price' => number_format((float)$priceWithTax, 2, '.', ''),
                'priceCurrency' => $currencyCode,
                'availability' => $product->isAvailable() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'url' => $product->getProductUrl(),
            ],
        ];

        if ($brandValue = $product->getAttributeText('manufacturer')) {
            $schema['brand'] = [
                '@type' => 'Brand',
                'name' => $brandValue,
            ];
        }

        return $this->jsonSerializer->serialize($schema);
    }
}
