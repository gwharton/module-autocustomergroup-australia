<?php
namespace Gw\AutoCustomerGroupAustralia\Model;

use Exception;
use GuzzleHttp\Exception\BadResponseException;
use Gw\AutoCustomerGroup\Api\Data\TaxIdCheckResponseInterface;
use Gw\AutoCustomerGroup\Api\Data\TaxIdCheckResponseInterfaceFactory;
use Gw\AutoCustomerGroup\Api\Data\TaxSchemeInterface;
use Magento\Directory\Model\Currency;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Information as StoreInformation;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use SoapClient;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Australian Test Numbers on Live system (No Sandbox)
 * https://abr.business.gov.au/Documentation/WebServiceResponse
 * 50 110 219 460 - EXAMPLE PTY LTD without GST
 * 72 629 951 766 - EXAMPLE HOUSE PTY LTD with GST
 */
class TaxScheme implements TaxSchemeInterface
{
    const CODE = "australiagst";
    const SCHEME_CURRENCY = 'AUD';
    const array SCHEME_COUNTRIES = ['AU'];

    /**
     * @var DateTime;
     */
    private $dateTime;

    /**
     * @var TaxIdCheckResponseInterfaceFactory
     */
    protected $ticrFactory;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var CurrencyFactory
     */
    public $currencyFactory;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     * @param StoreManagerInterface $storeManager
     * @param CurrencyFactory $currencyFactory
     * @param TaxIdCheckResponseInterfaceFactory $ticrFactory
     * @param DateTime $dateTime
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        StoreManagerInterface $storeManager,
        CurrencyFactory $currencyFactory,
        TaxIdCheckResponseInterfaceFactory $ticrFactory,
        DateTime $dateTime
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
        $this->currencyFactory = $currencyFactory;
        $this->ticrFactory = $ticrFactory;
        $this->dateTime = $dateTime;
    }

    /**
     * Get the order value, in scheme currency
     *
     * The "GST on low value imported goods" guide details how to handle shipments that contain multiple low value
     * items that will be shipped together, that when combined present an order value that is above 1,000 AUD.
     * In this case, even though individual items may be classed as low value items and would attract GST,
     * the value of the entire shipment is above the threshold and VAT should not be charged at the point
     * of sale.
     *
     * https://www.ato.gov.au/businesses-and-organisations/international-tax-for-business/gst-on-imported-goods-and-services/gst-on-low-value-imported-goods
     *
     * @param Quote $quote
     * @return float
     */
    public function getOrderValue(Quote $quote): float
    {
        $orderValue = 0.0;
        foreach ($quote->getItemsCollection() as $item) {
            $orderValue += ($item->getBaseRowTotal() - $item->getBaseDiscountAmount());
        }
        return $orderValue / $this->getSchemeExchangeRate($quote->getStoreId());
    }

    /**
     * Get customer group based on Validation Result and Country of customer
     * @param string $customerCountryCode
     * @param string|null $customerPostCode
     * @param bool $taxIdValidated
     * @param float $orderValue
     * @param int|null $storeId
     * @return int|null
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getCustomerGroup(
        string $customerCountryCode,
        ?string $customerPostCode,
        bool $taxIdValidated,
        float $orderValue,
        ?int $storeId
    ): ?int {
        $merchantCountry = $this->scopeConfig->getValue(
            StoreInformation::XML_PATH_STORE_INFO_COUNTRY_CODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if (empty($merchantCountry)) {
            $this->logger->critical(
                "Gw/AutoCustomerGroupAustralia/Model/TaxScheme::getCustomerGroup() : " .
                "Merchant country not set."
            );
            return null;
        }

        $importThreshold = $this->getThresholdInSchemeCurrency($storeId);

        //Merchant Country is in Australia
        //Item shipped to Australia
        //Therefore Domestic
        if (in_array($merchantCountry, self::SCHEME_COUNTRIES) &&
            in_array($customerCountryCode, self::SCHEME_COUNTRIES)) {
            return $this->scopeConfig->getValue(
                "autocustomergroup/" . self::CODE . "/domestic",
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }
        //Merchant Country is not in Australia
        //Item shipped to Australia
        //GST Validated ABN Number Supplied
        //Therefore Import B2B
        if (!in_array($merchantCountry, self::SCHEME_COUNTRIES) &&
            in_array($customerCountryCode, self::SCHEME_COUNTRIES) &&
            $taxIdValidated) {
            return $this->scopeConfig->getValue(
                "autocustomergroup/" . self::CODE . "/importb2b",
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }
        //Merchant Country is not in Australia
        //Item shipped to Australia
        //Order Value equal or below threshold
        //Therefore Import Taxed
        if (!in_array($merchantCountry, self::SCHEME_COUNTRIES) &&
            in_array($customerCountryCode, self::SCHEME_COUNTRIES) &&
            ($orderValue <= $importThreshold)) {
            return $this->scopeConfig->getValue(
                "autocustomergroup/" . self::CODE . "/importtaxed",
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }
        //Merchant Country is not in Australia
        //Item shipped to Australia
        //Order value below threshold
        //Therefore Import Unaxed
        if (!in_array($merchantCountry, self::SCHEME_COUNTRIES) &&
            in_array($customerCountryCode, self::SCHEME_COUNTRIES) &&
            ($orderValue > $importThreshold)) {
            return $this->scopeConfig->getValue(
                "autocustomergroup/" . self::CODE . "/importuntaxed",
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }
        return null;
    }

    /**
     * Peform validation of the ABN, returning a gatewayResponse object
     *
     * @param string $countryCode
     * @param string|null $taxId
     * @return TaxIdCheckResponseInterface
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function checkTaxId(
        string $countryCode,
        ?string $taxId
    ): TaxIdCheckResponseInterface {
        $taxIdCheckResponse = $this->ticrFactory->create();

        if (!in_array($countryCode, self::SCHEME_COUNTRIES)) {
            $taxIdCheckResponse->setRequestMessage(__('Unsupported country.'));
            $taxIdCheckResponse->setIsValid(false);
            $taxIdCheckResponse->setRequestSuccess(false);
            return $taxIdCheckResponse;
        }

        if ($taxId) {
            $taxId = str_replace([' ', '-'], ['', ''], $taxId);
        }

        $taxIdCheckResponse = $this->validateFormat($taxIdCheckResponse, $taxId);

        if ($taxIdCheckResponse->getIsValid() && $this->scopeConfig->isSetFlag(
                "autocustomergroup/" . self::CODE . "/validate_online",
                ScopeInterface::SCOPE_STORE
            )) {
            $taxIdCheckResponse = $this->validateOnline($taxIdCheckResponse, $taxId);
        }

        return $taxIdCheckResponse;
    }

    /**
     * Perform offline validation of the Tax Identifier
     *
     * @param $taxIdCheckResponse
     * @param $taxId
     * @return TaxIdCheckResponseInterface
     */
    private function validateFormat($taxIdCheckResponse, $taxId): TaxIdCheckResponseInterface
    {
        if (($taxId === null || strlen($taxId) < 1)) {
            $taxIdCheckResponse->setRequestMessage(__('You didn\'t supply an ABN to check.'));
            $taxIdCheckResponse->setIsValid(false);
            $taxIdCheckResponse->setRequestSuccess(true);
            return $taxIdCheckResponse;
        }
        if (preg_match("/^[0-9]{11}$/i", $taxId)) {
            $taxIdCheckResponse->setIsValid(true);
            $taxIdCheckResponse->setRequestSuccess(true);
            $taxIdCheckResponse->setRequestMessage(__('ABN is the correct format.'));
        } else {
            $taxIdCheckResponse->setIsValid(false);
            $taxIdCheckResponse->setRequestSuccess(true);
            $taxIdCheckResponse->setRequestMessage(__('ABN is not the correct format.'));
            return $taxIdCheckResponse;
        }
        if ($this->isValidAbn($taxId)) {
            $taxIdCheckResponse->setIsValid(true);
            $taxIdCheckResponse->setRequestSuccess(true);
            $taxIdCheckResponse->setRequestMessage(__('ABN is validated (Offline).'));
        } else {
            $taxIdCheckResponse->setIsValid(false);
            $taxIdCheckResponse->setRequestSuccess(true);
            $taxIdCheckResponse->setRequestMessage(__('ABN is not valid (Offline).'));
            return $taxIdCheckResponse;
        }
        return $taxIdCheckResponse;
    }

    /**
     * Perform online validation of the Tax Identifier
     *
     * @param $taxIdCheckResponse
     * @param $taxId
     * @return TaxIdCheckResponseInterface
     */
    private function validateOnline($taxIdCheckResponse, $taxId): TaxIdCheckResponseInterface
    {
        $apiguid = $this->scopeConfig->getValue(
            "autocustomergroup/" . self::CODE . "/apiguid",
            ScopeInterface::SCOPE_STORE
        );
        if (empty($apiguid)) {
            $this->logger->critical(
                "Gw/AutoCustomerGroupUk/Model/TaxScheme::checkTaxId() : API GUID not set."
            );
            $taxIdCheckResponse->setRequestMessage(__('API GUID not set.'));
            $taxIdCheckResponse->setIsValid(false);
            $taxIdCheckResponse->setRequestSuccess(false);
            return $taxIdCheckResponse;
        }

        try {
            $soapClient = new SoapClient("https://abr.business.gov.au/abrxmlsearch/ABRXMLSearch.asmx?WSDL");

            $requestParams = [];
            $requestParams['searchString'] = $taxId;
            $requestParams['includeHistoricalDetails'] ='N';
            $requestParams['authenticationGuid'] = $apiguid;

            $result = $soapClient->ABRSearchByABN($requestParams);

            $isCurrent = $result->ABRPayloadSearchResults->response->businessEntity->ABN->isCurrentIndicator;
            $gstValid = false;

            if (isset($result->ABRPayloadSearchResults->response->businessEntity->goodsAndServicesTax)) {
                $gstValid = true;
                $gstFrom = $result->ABRPayloadSearchResults->response
                    ->businessEntity->goodsAndServicesTax->effectiveFrom;
                $day = $this->dateTime->gmtDate("Y-m-d");
                if ($gstFrom > $day) {
                    $gstValid = false;
                } else {
                    $gstTo = $result->ABRPayloadSearchResults->response
                        ->businessEntity->goodsAndServicesTax->effectiveTo;
                    if (($gstTo != "0001-01-01") &&
                        ($gstTo < $day)) {
                        $gstValid = false;
                    }
                }
            }
            $identifier = $result->ABRPayloadSearchResults->response->businessEntity->ABN->identifierValue;
            $datetime = $result->ABRPayloadSearchResults->response->dateTimeRetrieved;

            if (preg_match("/^[Y](es)?$/i", $isCurrent) &&
                $gstValid &&
                !empty($datetime)) {
                $taxIdCheckResponse->setRequestIdentifier($identifier);
                $taxIdCheckResponse->setRequestDate($datetime);
                $taxIdCheckResponse->setRequestSuccess(true);
                $taxIdCheckResponse->setIsValid(true);
                $taxIdCheckResponse->setRequestMessage(__('ABN validated and business is registered for GST with ATO.'));
            } else {
                $taxIdCheckResponse->setIsValid(false);
                $taxIdCheckResponse->setRequestSuccess(true);
                $taxIdCheckResponse->setRequestDate('');
                $taxIdCheckResponse->setRequestMessage(__('Please enter a valid ABN number, where the business is registered for GST.'));
            }
        } catch (Exception $exception) {
            $taxIdCheckResponse->setIsValid(false);
            $taxIdCheckResponse->setRequestSuccess(false);
            $taxIdCheckResponse->setRequestDate('');
            $taxIdCheckResponse->setRequestIdentifier('');
            $taxIdCheckResponse->setRequestMessage(__('There was an error checking the ABN.'));
        }
        return $taxIdCheckResponse;
    }

    /**
     * Validate an Australian Business Number (ABN)
     *
     * @param string|null $abn
     * @return bool
     */
    public function isValidAbn(?string $abn): bool
    {
        $weights = [10, 1, 3, 5, 7, 9, 11, 13, 15, 17, 19];

        // Strip non-numbers from the acn
        $abn = preg_replace('/[^0-9]/', '', $abn ?? "");

        // Check abn is 11 chars long
        if (strlen($abn) != 11) {
            return false;
        }

        // Subtract one from first digit
        $abn[0] = ((int)$abn[0] - 1);

        // Sum the products
        $sum = 0;
        foreach (str_split($abn) as $key => $digit) {
            $sum += ((int) $digit * $weights[$key]);
        }

        if (($sum % 89) != 0) {
            return false;
        }
        return true;
    }

    /**
     * Get the scheme name
     *
     * @return string
     */
    public function getSchemeName(): string
    {
        return __("Australia GST for Non Residents Scheme");
    }

    /**
     * Get the scheme code
     *
     * @return string
     */
    public function getSchemeId(): string
    {
        return self::CODE;
    }

    /**
     * @param int|null $storeId
     * @return string|null
     */
    public function getFrontEndPrompt(?int $storeId): ?string
    {
        return $this->scopeConfig->getValue(
            "autocustomergroup/" . self::CODE . "/frontendprompt",
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @return string
     */
    public function getSchemeCurrencyCode(): string
    {
        return self::SCHEME_CURRENCY;
    }

    /**
     * @param int|null $storeId
     * @return float
     */
    public function getThresholdInSchemeCurrency(?int $storeId): float
    {
        return $this->scopeConfig->getValue(
            "autocustomergroup/" . self::CODE . "/importthreshold",
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @param int|null $storeId
     * @return string|null
     */
    public function getSchemeRegistrationNumber(?int $storeId): ?string
    {
        return $this->scopeConfig->getValue(
            "autocustomergroup/" . self::CODE . "/registrationnumber",
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @return array
     */
    public function getSchemeCountries(): array
    {
        return self::SCHEME_COUNTRIES;
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            "autocustomergroup/" . self::CODE . "/enabled",
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @param int|null $storeId
     * @return float
     */
    public function getSchemeExchangeRate(?int $storeId): float
    {
        if ($this->scopeConfig->isSetFlag(
            "autocustomergroup/" . self::CODE . '/usemagentoexchangerate',
            ScopeInterface::SCOPE_STORE,
            $storeId
        )) {
            $websiteBaseCurrency = $this->scopeConfig->getValue(
                Currency::XML_PATH_CURRENCY_BASE,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
            $exchangerate = $this->currencyFactory
                ->create()
                ->load($this->getSchemeCurrencyCode())
                ->getAnyRate($websiteBaseCurrency);
            if (!$exchangerate) {
                $this->logger->critical(
                    "Gw/AutoCustomerGroupAustralia/Model/TaxScheme::getSchemeExchangeRate() : " .
                    "No Magento Exchange Rate configured for " . self::SCHEME_CURRENCY . " to " .
                    $websiteBaseCurrency . ". Using 1.0"
                );
                $exchangerate = 1.0;
            }
            return (float)$exchangerate;
        }
        return (float)$this->scopeConfig->getValue(
            "autocustomergroup/" . self::CODE . "/exchangerate",
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
