# CiviCRM Tsys Payment Processor

Integrates the [TSYS®](https://www.tsys.com/) payment processor (for Credit/Debit cards) into CiviCRM so it can be used it to accept Credit / Debit card payments on your site.

With TSYS® you get a Top 100 Global Technology Leader, as named by Thomson Reuters, and more than 35 years of payment experience helping businesses like yours. TSYS provides seamless, secure and innovative solutions across the payments spectrum. They succeed because they put people, and their needs, at the heart of every decision. It’s an approach they call ‘People-Centered Payments®’. TSYS is a member of [The Civic 50](https://www.pointsoflight.org/) and only payments company to achieve [World's Most Ethical Companies®](https://www.worldsmostethicalcompanies.com/honorees/) by Ethisphere Institute in 2018. Whether you only take payments online, by mobile app or by phone – or need a solution that integrates in-person payments with your online payments – TSYS has convenient options for people to pay you that help you manage your business.  Gain peace of mind knowing the payments you receive are encrypted and tokenized for greater security. Receive payments faster and increase the frequency you receive payments with automated options for scheduled and recurring payments.

For more information visit: https://docs.civicrm.org/tsys/en/latest/

## Configuration
All configuration is in the standard Payment Processors settings area in CiviCRM admin
You will need to enter the following credentials which will be provided on creation of your Tsys Account:

+ Merchant Name
+ Web API Key
+ Merchant Key
+ Merchant Site

## Installation
There are no special installation requirements.
The extension will show up in the extensions browser for automated installation.
Otherwise, download and install as you would for any other CiviCRM extension.

## When Recurring Transactions Fail
The status of the Recurring Contribution should be set to Pending and a message should appear on the System Status page.

## Contribute Transact API
This processor works with the Contribution Transact API but one needs to pass the Currency as USD.

## Testing
[Credit Card Numbers to test with](https://docs.cayan.com/knowledge-base/testing-certification-tools/test-processor)

### To Run phpunit tests:
$ env CIVICRM_UF=UnitTests TSYS_user_name='name' TSYS_password="webApiKey" TSYS_signature="Key" TSYS_subject="siteId" phpunit5 ./tests/phpunit/CRM/Tsys/OneTimeContributionTsysTest.php
