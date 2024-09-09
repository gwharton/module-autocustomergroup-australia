<?php

namespace Gw\AutoCustomerGroupAustralia\Test\Unit;

use GuzzleHttp\ClientFactory;
use Gw\AutoCustomerGroup\Model\TaxSchemeHelper;
use Gw\AutoCustomerGroupAustralia\Model\TaxScheme;
use Gw\AutoCustomerGroup\Api\Data\TaxIdCheckResponseInterfaceFactory;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TaxSchemeTest extends TestCase
{
    /**
     * @var TaxScheme
     */
    private $model;

    /**
     * @var ScopeConfigInterface|MockObject
     */
    private $scopeConfigMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private $loggerMock;

    /**
     * @var StoreManagerInterface|MockObject
     */
    private $storeManagerMock;

    /**
     * @var CurrencyFactory|MockObject
     */
    private $currencyFactoryMock;

    /**
     * @var TaxIdCheckResponseInterfaceFactory|MockObject
     */
    private $taxIdCheckResponseInterfaceFactoryMock;

    /**
     * @var DateTime|MockObject
     */
    private $dateTimeMock;

    /**
     * @var TaxSchemeHelper|MockObject
     */
    private $helperMock;

    protected function setUp(): void
    {
        $this->scopeConfigMock = $this->getMockBuilder(ScopeConfigInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->loggerMock = $this->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->storeManagerMock = $this->getMockBuilder(StoreManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->currencyFactoryMock = $this->getMockBuilder(CurrencyFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->taxIdCheckResponseInterfaceFactoryMock = $this->getMockBuilder(TaxIdCheckResponseInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->dateTimeMock = $this->getMockBuilder(DateTime::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->helperMock = $this->getMockBuilder(TaxSchemeHelper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->model = new TaxScheme(
            $this->scopeConfigMock,
            $this->loggerMock,
            $this->storeManagerMock,
            $this->currencyFactoryMock,
            $this->taxIdCheckResponseInterfaceFactoryMock,
            $this->dateTimeMock,
            $this->helperMock
        );
    }

    public function testGetSchemeName(): void
    {
        $schemeName = $this->model->getSchemeName();
        $this->assertIsString($schemeName);
        $this->assertGreaterThan(0, strlen($schemeName));
    }

    /**
     * @param $number
     * @param $valid
     * @return void
     * @dataProvider isValidAbnDataProvider
     */
    public function testIsValidAbn($number, $valid): void
    {
        $this->assertEquals($valid, $this->model->isValidAbn($number));
    }

    /**
     * Data provider for testIsValidAbn()
     *
     * @return array
     */
    public function isValidAbnDataProvider(): array
    {
        //Valid numbers from https://abr.business.gov.au/Search/ResultsActive?SearchText=example
        return [
            ["50 110 219 460", true],
            ["99 644 068 913", true],
            ["36 643 591 119", true],
            ["90 929 922 193", true],
            ["58 630 144 375", true],
            ["oygyg", false],
            ["98 765 432 111", false],
            ["12 345 678 999", false],
            ["", false]
        ];
    }
}

