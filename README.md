# Feedamp Webhook SDK
Feedamp Webhook SDK is a sdk that allows developers to build REST apis to integrate third-party client platforms with Feedamp.


## Requirements
PHP 8+
composer
webserver configured to run php

## Installation
Checkout this repo
In the root of the checkout run:  
```
composer install
```

## Sample application
A sample REST API to integrate Shopify with Feedamp has been included in the /public directory

A .httaccess file, for Apache webservers, with the necessary re-write rules is also included.

Configure your webserver so the document root for the domain is the /public directory.

## Additional Documentation
The OpenAPI specs describing the expected api endpoints, payloads and responses for Feedamp integration is located here: 
/resources/openapi.yaml

A json schema can be found in /resources/json_schema.json  This file can be used to validate payloads and responses.  
This file is used by the sample application to validate the API request payloads using /src/services/JsonSchemaValidator   

A Postman collection has been added in /resources/WebhookEndpoints.postman_collection.json  This contains test cases for 
the sample application

Sample test case files for placing orders via FTP callback have been provided here: 
/resources/ftp_sample_files/place_orders

- Success reading of the file
  - place_orders.csv - contains correct header information and order test cases. This contains test cases such as: 
    - Orders that are able to be successfully placed into client platform
    - Orders that are not attempted to be placed into the client platform as they fail internal validation rules
    - Orders that fail to be placed into client platform due to validation rules in the client platform
    - Order that the client platform cannot validate due to a bad phone number. The app automatically removes the phone 
      number and tries to re-places the order (successfully)
  - results/place_orders_results.csv - The expected results file for the orders in the place_orders.csv file
- Expected failure cases (file format is wrong)
  - error_unparsable_missing_header.csv - test case where the ftp file is missing required headers
  - error_unparsable_unknown_header.csv - test case where the ftp file contains the number of expected fields but one is unknown
  - error_unparsable_extra_header.csv - test case where the ftp file contains all expected header field and an unknown one
  
## Contributing

Pull requests are welcome. For major changes, please open an issue first
to discuss what you would like to change.

## License

[MIT](https://choosealicense.com/licenses/mit/)
