<?php

namespace Gw\AutoCustomerGroupAustralia\Test\Integration;

use Gw\AutoCustomerGroupAustralia\Model\TaxScheme;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\GuestCartManagementInterface;
use Magento\Quote\Api\GuestCartRepositoryInterface;
use Magento\Store\Model\Information as StoreInformation;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 * @magentoAppArea frontend
 */
class TaxSchemeTest extends TestCase
{
    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var TaxScheme
     */
    private $taxScheme;

    /**
     * @var ReinitableConfigInterface
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ProductFactory
     */
    private $productFactory;

    /**
     * @var GuestCartManagementInterface
     */
    private $guestCartManagement;

    /**
     * @var GuestCartRepositoryInterface
     */
    private $guestCartRepository;

    /**
     * @var CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager = Bootstrap::getObjectManager();
        $this->taxScheme = $this->objectManager->get(TaxScheme::class);
        $this->config = $this->objectManager->get(ReinitableConfigInterface::class);
        $this->storeManager = $this->objectManager->get(StoreManagerInterface::class);
        $this->productFactory = $this->objectManager->get(ProductFactory::class);
        $this->guestCartManagement = $this->objectManager->get(GuestCartManagementInterface::class);
        $this->guestCartRepository = $this->objectManager->get(GuestCartRepositoryInterface::class);
        $this->quoteRepository = $this->objectManager->get(CartRepositoryInterface::class);
        $this->productRepository = $this->objectManager->get(ProductRepositoryInterface::class);
    }

    /**
     * @magentoAdminConfigFixture current_store currency/options/default GBP
     * @magentoAdminConfigFixture current_store currency/options/base GBP
     * @magentoConfigFixture current_store autocustomergroup/australiagst/usemagentoexchangerate 0
     * @magentoConfigFixture current_store autocustomergroup/australiagst/exchangerate 0.4985
     * @dataProvider getOrderValueDataProvider
     */
    public function testGetOrderValue(
        $qty1,
        $price1,
        $qty2,
        $price2,
        $expectedValue
    ): void {
        $product1 = $this->productFactory->create();
        $product1->setTypeId('simple')
            ->setId(1)
            ->setAttributeSetId(4)
            ->setWebsiteIds([1])
            ->setName('Simple Product 1')
            ->setSku('simple1')
            ->setPrice($price1)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setStockData(['use_config_manage_stock' => 0]);
        $this->productRepository->save($product1);
        $product2 = $this->productFactory->create();
        $product2->setTypeId('simple')
            ->setId(2)
            ->setAttributeSetId(4)
            ->setWebsiteIds([1])
            ->setName('Simple Product 2')
            ->setSku('simple2')
            ->setPrice($price2)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setStockData(['use_config_manage_stock' => 0]);
        $this->productRepository->save($product2);
        $maskedCartId = $this->guestCartManagement->createEmptyCart();
        $quote = $this->guestCartRepository->get($maskedCartId);
        $quote->addProduct($product1, $qty1);
        $quote->addProduct($product2, $qty2);
        $this->quoteRepository->save($quote);
        $result = $this->taxScheme->getOrderValue(
            $quote
        );
        $this->assertEqualsWithDelta($expectedValue, $result, 0.009);
    }

    /**
     * Remember for AUS, it is the sum of the item prices that counts,
     * i.e total order value including discounts without shipping
     *
     * @return array
     */
    public function getOrderValueDataProvider(): array
    {
        // Quantity 1
        // Base Price 1
        // Quantity 2
        // Base Price 2
        // Expected Order Value Scheme Currency
        return [
            [1, 100.00, 2, 50.00, 401.20],  // 200.00GBP in AUD
            [2, 100.00, 1, 100.00, 601.80], // 400.00GBP in AUD
            [7, 25.50, 2, 0.99, 362.05],    // 180.48GBP in AUD
            [7, 25.50, 4, 100.00, 1160.48],  // 578.50GBP in AUD
            [7, 25.50, 10, 1000.00, 20418.25] // 10178.50GBP in AUD
        ];
    }

    /**
     * @magentoConfigFixture current_store autocustomergroup/australiagst/domestic 1
     * @magentoConfigFixture current_store autocustomergroup/australiagst/importb2b 2
     * @magentoConfigFixture current_store autocustomergroup/australiagst/importtaxed 3
     * @magentoConfigFixture current_store autocustomergroup/australiagst/importuntaxed 4
     * @magentoConfigFixture current_store autocustomergroup/australiagst/importthreshold 1000
     * @dataProvider getCustomerGroupDataProvider
     */
    public function testGetCustomerGroup(
        $merchantCountryCode,
        $merchantPostCode,
        $customerCountryCode,
        $customerPostCode,
        $taxIdValidated,
        $orderValue,
        $expectedGroup
    ): void {
        $storeId = $this->storeManager->getStore()->getId();
        $this->config->setValue(
            StoreInformation::XML_PATH_STORE_INFO_COUNTRY_CODE,
            $merchantCountryCode,
            ScopeInterface::SCOPE_STORE
        );
        $this->config->setValue(
            StoreInformation::XML_PATH_STORE_INFO_POSTCODE,
            $merchantPostCode,
            ScopeInterface::SCOPE_STORE
        );
        $result = $this->taxScheme->getCustomerGroup(
            $customerCountryCode,
            $customerPostCode,
            $taxIdValidated,
            $orderValue,
            $storeId
        );
        $this->assertEquals($expectedGroup, $result);
    }

    /**
     * @return array
     */
    public function getCustomerGroupDataProvider(): array
    {
        //Merchant Country Code
        //Merchant Post Code
        //Customer Country Code
        //Customer Post Code
        //taxIdValidated
        //OrderValue
        //Expected Group
        return [
            // AU to AU, value doesn't matter, GST number status doesn't matter - Domestic
            ['AU', null, 'AU', null, false, 999, 1],
            ['AU', null, 'AU', null, true, 1001, 1],
            ['AU', null, 'AU', null, false, 999, 1],
            ['AU', null, 'AU', null, false, 999, 1],
            ['AU', null, 'AU', null, true, 999, 1],
            ['AU', null, 'AU', null, false, 1001, 1],
            ['AU', null, 'AU', null, false, 1001, 1],
            // Import into AU, value doesn't matter, valid GST - Import B2B
            ['FR', null, 'AU', null, true, 999, 2],
            ['FR', null, 'AU', null, true, 1001, 2],
            // Import into AU, value below threshold, Should only be B2C at this point - Import Taxed
            ['FR', null, 'AU', null, false, 999, 3],
            ['FR', null, 'AU', null, false, 999, 3],
            // Import into AU, value above threshold, Should only be B2C at this point - Import Untaxed
            ['FR', null, 'AU', null, false, 1001, 4],
            ['FR', null, 'AU', null, false, 1001, 4],
        ];
    }

    /**
     * @magentoConfigFixture current_store autocustomergroup/australiagst/validate_online 1
     * @dataProvider checkTaxIdDataProviderOnline
     *
     */
    public function testCheckTaxIdOnline(
        $countryCode,
        $taxId,
        $isValid
    ): void {
        $result = $this->taxScheme->checkTaxId(
            $countryCode,
            $taxId
        );
        $this->assertEquals($isValid, $result->getIsValid());
    }

    /**
     * @return array
     */
    public function checkTaxIdDataProviderOnline(): array
    {
        //Country code
        //Tax Id
        //IsValid
        return [
            ['AU', '',                  false],
            ['AU', null,                false],
            ['AU', '72 629 951 766',    true], // Correct format, valid ABN, valid GST
            ['AU', '50 110 219 460',    false], // Correct format, valid ABN, no GST
            ['AU', '9429032097351',     false],
            ['AU', '9429036975273',     false],
            ['AU', 'htght',             false],
            ['AU', 'nntreenhrhnjrehh',  false],
            ['AU', '4363546743743',     false],
            ['AU', '7234767476612',     false],
            ['AU', 'th',                false],
            ['AU', '786176152',         false],
            ['GB', '72 629 951 766',    false], // Unsupported country, despite valid number
        ];
    }

    /**
     * @dataProvider checkTaxIdDataProviderOfline
     *
     */
    public function testCheckTaxIdOffline(
        $countryCode,
        $taxId,
        $isValid
    ): void {
        $result = $this->taxScheme->checkTaxId(
            $countryCode,
            $taxId
        );
        $this->assertEquals($isValid, $result->getIsValid());
    }

    /**
     * @return array
     */
    public function checkTaxIdDataProviderOfline(): array
    {
        //Country code
        //Tax Id
        //IsValid
        return [
            ['AU', '',                  false],
            ['AU', null,                false],
            ['AU', '72 629 951 766',    true], // Correct format
            ['AU', '50 110 219 460',    true], // Correct format
            ['AU', '9429032097351',     false],
            ['AU', '9429036975273',     false],
            ['AU', 'htght',             false],
            ['AU', 'nntreenhrhnjrehh',  false],
            ['AU', '4363546743743',     false],
            ['AU', '7234767476612',     false],
            ['AU', 'th',                false],
            ['AU', '786176152',         false]
        ];
    }
}

