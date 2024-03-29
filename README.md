# CiviCRM Genius Platform by Global Payments Integrated (formerly TSYS) Processor

Integrates the [TSYS®](https://www.tsys.com/) payment processor (for Credit/Debit cards) into CiviCRM so it can be used it to accept Credit / Debit card payments on your site.

With TSYS® you get a Top 100 Global Technology Leader, as named by Thomson Reuters, and more than 35 years of payment experience helping businesses like yours. TSYS provides seamless, secure and innovative solutions across the payments spectrum. They succeed because they put people, and their needs, at the heart of every decision. It’s an approach they call ‘People-Centered Payments®’. TSYS is a member of [The Civic 50](https://www.pointsoflight.org/) and only payments company to achieve [World's Most Ethical Companies®](https://www.worldsmostethicalcompanies.com/honorees/) by Ethisphere Institute in 2018. Whether you only take payments online, by mobile app or by phone – or need a solution that integrates in-person payments with your online payments – TSYS has convenient options for people to pay you that help you manage your business.  Gain peace of mind knowing the payments you receive are encrypted and tokenized for greater security. Receive payments faster and increase the frequency you receive payments with automated options for scheduled and recurring payments.

Currently one-off and recurring payments by Credit/Debit card are supported for USD only.  In the future it is hoped to support additional payment methods.

For bug reports / feature requests for this extension please use the issue queue: https://github.com/aghstrategies/com.aghstrategies.tsys/issues

For more information visit: https://docs.civicrm.org/tsys/en/latest/

## Sign Up
To open an account with TSYS, [click here to fill out the referral form](https://lp.globalpaymentsintegrated.com/referrals/aghstrategies/). TSYS has staff dedicated to supporting CiviCRM, and one of them will follow up to discuss processing rates.

## Configuration
All configuration is in the standard Payment Processors settings area in CiviCRM admin
You will need to enter the following credentials which will be provided on creation of your Tsys Account:

+ Merchant Name
+ Web API Key
+ Merchant Key
+ Merchant Site

![screenshot of form to configure TSYS payment Processor](/images/screenToConfigureTSYScredentials.png)

## Installation
There are no special installation requirements.
The extension will show up in the extensions browser for automated installation.
Otherwise, download and install as you would for any other CiviCRM extension.

## When Recurring Transactions Fail
The status of the Recurring Contribution should be set to Pending and a message should appear on the System Status page.

## Devices
This Payment Processor works with TSYS countertop Devices
New Devices can be configured on the TSYS Settings Form (CiviCRM Admin Menu -> Administer -> TSYS Settings):

### TSYS Settings Form
![screenshot of tsys settings form](/images/TSYSSettings.png)

### Add Device Form
![screenshot of add device form](/images/newDevice.png)

### Add Payment Via Device Form
Once configured Payments can be made using the devices one of two ways:
1. A simple contribution can be made (with a payment for the full amount) thru the "Submit Credit Card Contribution Via Device" Form (CiviCRM Admin Menu -> Contributions -> Submit Credit Card Contribution Via Device).
2. A payment can be made against an existing contribution using the "» Submit payment via {deviceName} device" links which can be found when viewing or editing eligible Contributions (contributions with the status "Pending" or "Partially Paid") and on the Record Additional Payment Form.
![screenshot of record payment from device link on view contribution](/images/view.png)

## Refunds
This Payment Processor allows the user to refund payments made thru GPI from CiviCRM by:
1. going to a contribution in View or Edit mode
2. Clicking the Refund Link  
![screenshot of refund link](/images/refundLink.png)
3. Entering the amount to refund (this will be prepopulated as the total payment amount).
4. Clicking Issue Refund

NOTE CiviCRM has other Refund workflows which will result in the Contribution in CiviCRM to be updated to the status Refunded but not update TSYS.

## Testing
[Credit Card Numbers to test with](https://developer.globalpay.com/resources/test-card-numbers)

### To Run phpunit tests:
$ env CIVICRM_UF=UnitTests TSYS_user_name='name' TSYS_password="webApiKey" TSYS_signature="Key" TSYS_subject="siteId" phpunit5 ./tests/phpunit/CRM/Tsys/OneTimeContributionTsysTest.php

## Versioning
v.MAJOR.MINOR.PATCH

## Wishlist Features
+ Process device payments from the Create Contribution Form
+ Process device payments from the Create Event Registration Form
+ Add a system check for if the Root Certificate is installed correctly
+ Refund a Contribution (not just individual payments)
+ Rebase unit tests onto newer core methods
