<h1>AutoCustomerGroup - Australia Addon</h1>
<p>Magento 2 Module - Module to add Australia functionality to gwharton/module-autocustomergroup</p>

<h2>Australia GST for Non Residents Scheme</h2>
<p>This Scheme applies to shipments being sent from anywhere in the world to Consumers (Not B2B transactions) in Australia.</p>
<p>As of 1st July 2017, all sellers must (if their turnover to Australia exceeds 75,000 AUD) register for the Australia GST for Non Residents scheme,
    and collect GST for B2C transactions at the point of sale and remit to the Australian Government.</p>
<p>The module is capable of automatically assigning customers to the following categories.</p>
<ul>
    <li><b>Domestic</b> - For shipments within Australia, normal Australia GST rules apply.</li>
    <li><b>Import B2B</b> - For shipments from outside of Australia to Australia and the buyer presents a validated GST ABN number, then GST should not be charged.</li>
    <li><b>Import Taxed</b> - For imports into Australia, where the order value is equal to or below 1,000 AUD, then GST should be charged.</li>
    <li><b>Import Untaxed</b> - For imports into Australia, where the order value is above 1,000 AUD, then GST should NOT be charged and instead will be collected at the Australia border along with any duties due.</li>
</ul>
<p>You need to create the appropriate tax rules and customer groups, and assign these customer groups to the above categories within the module configuration. Please ensure you fully understand the tax rules of the country you are shipping to. The above should only be taken as a guide.</p>

<h2>Government Information</h2>
<p>Scheme information can be found <a href="https://www.ato.gov.au/businesses-and-organisations/international-tax-for-business/gst-on-imported-goods-and-services/gst-on-low-value-imported-goods" target="_blank">on the ATO website here</a>.</p>

<h2>Order Value</h2>
<p>For the Australia GST for Non Residents Scheme, the following applies (This can be confirmed
    <a href="https://www.ato.gov.au/businesses-and-organisations/international-tax-for-business/gst-on-imported-goods-and-services/gst-on-low-value-imported-goods"
    target="_blank">here</a>) :</p>
<ul>
    <li>Order value (for the purpose of thresholding) is the sum of the sale price of all items sold (including any discounts)</li>
    <li>When determining whether GST should be charged (GST Threshold) Shipping or Insurance Costs are not included in the value of the goods.</li>
    <li>When determining the amount of GST to charge the Goods value does include Shipping and Insurance Costs.</li>
</ul>
<p>The <a href="https://www.ato.gov.au/businesses-and-organisations/international-tax-for-business/gst-on-imported-goods-and-services/gst-on-low-value-imported-goods" target="_blank">GST on low value imported goods</a>
    guide details how to handle shipments that contain multiple low value items that will be shipped together, that when combined present an order
    value that is above 1,000 AUD. In this case, even though individual items may be classed as low value items and would attract GST, the value
    of the entire shipment is above the threshold and VAT should not be charged at the point of sale.</p>
<p>More information on the scheme can be found on the
    <a href="https://www.ato.gov.au/businesses-and-organisations/international-tax-for-business/gst-on-imported-goods-and-services/what-to-do-as-a-non-resident-business" target="_blank">Australian Tax Office Website</a></p>

<h2>Pseudocode for group allocation</h2>
<p>Groups are allocated by evaluating the following rules in this order (If a rule matches, no further rules are evaluated).</p>
<ul>
<li>IF MerchantCountry IS Australia AND CustomerCountry IS Australia THEN Group IS Domestic.</li>
<li>IF MerchantCountry IS NOT Australia AND CustomerCountry IS Australia AND TaxIdentifier IS VALID THEN Group IS ImportB2B.</li>
<li>IF MerchantCountry IS NOT Australia AND CustomerCountry IS Australia AND OrderValue IS LESS THAN OR EQUAL TO Threshold THEN Group IS ImportTaxed.</li>
<li>IF MerchantCountry IS NOT Australia AND CustomerCountry IS Australia AND OrderValue IS MORE THAN Threshold THEN Group IS ImportUntaxed.</li>
<li>ELSE NO GROUP CHANGE</li>
</ul>

<h2>ABN Number Verification</h2>
<ul>
<li><b>Offline Validation</b> - A simple format and checksum validation is performed.</li>
<li><b>Online Validation</b> - In addition to the offline checks above, an online validation check is performed with the Australian Tax Office ABN checker service. The online check not only ensures that the ABN number is valid, but also checks that the ABN number has an associated GST number registered with it, and it is in date and valid.</li>
</ul>
<p>Credentials for the Australian Tax Office ABN checker API can be obtained by signing up on the <a href="https://abr.business.gov.au/Tools/WebServices" target="_blank">Australian Business Register Website</a>.</p>

<h2>Configuration Options</h2>
<ul>
<li><b>Enabled</b> - Enable/Disable this Tax Scheme.</li>
<li><b>Tax Identifier Field - Customer Prompt</b> - Displayed under the Tax Identifier field at checkout when a shipping country supported by this module is selected. Use this to include information to the user about why to include their Tax Identifier.</li>
<li><b>Validate Online</b> - Whether to validate GST Registration status with the Australian Business Register using the ABN number, or just perform simple format validation.</li>
<li><b>ABN API GUID</b> - The GUID provided by the Australian Business Register website for API access.</li>
<li><b>ATO Registration Number</b> - The ATO Registration Number for the Merchant. This is not currently used by the module, however supplementary functions in AutoCustomerGroup may use this, for example displaying on invoices etc.</li>
<li><b>Import GST Threshold</b> - If the order value is above the GST Threshold, no GST should be charged.</li>
<li><b>Use Magento Exchange Rate</b> - To convert from AUD Threshold to Store Currency Threshold, should we use the Magento Exchange Rate, or our own.</li>
<li><b>Exchange Rate</b> - The exchange rate to use to convert from AUD Threshold to Store Currency Threshold.</li>
<li><b>Customer Group - Domestic</b> - Merchant Country is within Australia, Item is being shipped to Australia.</li>
<li><b>Customer Group - Import B2B</b> - Merchant Country is not within Australia, Item is being shipped to Australia, ABN Number passed validation by module.</li>
<li><b>Customer Group - Import Taxed</b> - Merchant Country is not within Australia, Item is being shipped to Australia, Order Value is below or equal to the Import GST Threshold.</li>
<li><b>Customer Group - Import Untaxed</b> - Merchant Country is not within Australia, Item is being shipped to Australia, Order Value is above the Import GST Threshold.</li>
</ul>

<h2>Integration Tests</h2>
<p>To run the integration tests, you need your own credentials for the Australian Business Register API. Please add them to config-global.php.</p>
<p>Please note that the Australian Business Register API does not have a sandbox for testing, so a live API GUID should be used.</p>
<ul>
<li>autocustomergroup/australiagst/apiguid</li>
</ul>
