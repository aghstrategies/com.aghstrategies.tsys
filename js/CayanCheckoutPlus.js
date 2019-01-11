var CayanCheckoutPlus = (function () {

    // private members
    var cayanApiToken = "";
    var targetDocument = window.document;
    var iFrameId = "dataFrame";

    var API_URL = "https://ecommerce.merchantware.net/v1/api/tokens";
    var API_PLUS_URL = "https://ecommerce.merchantware.net/v1/api/session";

    var errorCodes = {
        required: 'REQUIRED',
        validation: 'VALIDATION',
        notFound: 'NOT_FOUND',
        server: 'SERVER'
    };

    // inner class to handle validation
    var Validation = function () { };

    Validation.isNullOrWhitespace = function (str) {
        return !str || str.match(/^ *$/) !== null;
    };

    Validation.isNumeric = function (value) {
        return value.match(/^[0-9]*$/);
    };

    Validation.lunhCheck = function (number) {
        if (!Validation.isNumeric(number)) {
            return false;
        }
        if (number.length < 13 || number.length > 19) {
            return false;
        }
        var sum = 0;
        for (var i = number.length - 1; i > -1; i--) {
            var digit = parseInt(number.charAt(i));
            if ((number.length - i) % 2 == 0) {
                var doubled = digit * 2;
                digit = (doubled > 9) ? doubled - 9 : doubled;
            }
            sum += digit;
        }
        return sum % 10 == 0;
    };

    Validation.validateCVV = function (tokenRequest, validationErrors) {
        if (Validation.isNullOrWhitespace(tokenRequest.cvv)) {
            return false;
        }
        var cvvLength = tokenRequest.cvv.length;
        if (!Validation.isNumeric(tokenRequest.cvv) || cvvLength > 4 || cvvLength < 3) {
            validationErrors.push({
                error_code: errorCodes.validation,
                reason: "cvv"
            });
            return false;
        }
        return true;
    };

    Validation.validateCardNumber = function (tokenRequest, validationErrors) {
        if (Validation.isNullOrWhitespace(tokenRequest.cardnumber)) {
            return false;
        }
        if (!Validation.lunhCheck(tokenRequest.cardnumber)) {
            validationErrors.push({
                error_code: errorCodes.validation,
                reason: "cardnumber"
            });
            return false;
        }
        return true;
    };

    Validation.validateExpirationDate = function (tokenRequest, validationErrors) {
        if (Validation.isNullOrWhitespace(tokenRequest.expirationmonth) || Validation.isNullOrWhitespace(tokenRequest.expirationyear)) {
            return false;
        }
        // check if the math is valid
        if (!Validation.isNumeric(tokenRequest.expirationmonth) || tokenRequest.expirationmonth.length > 2) {
            validationErrors.push({
                error_code: errorCodes.validation,
                reason: "expirationmonth"
            });
            return false;
        }

        // check if the year is valid
        if (!Validation.isNumeric(tokenRequest.expirationyear) || (tokenRequest.expirationyear.length !== 2 && tokenRequest.expirationyear.length !== 4)) {
            validationErrors.push({
                error_code: errorCodes.validation,
                reason: "expirationyear"
            });
            return false;
        }

        var today = new Date();
        var thisCentury = Math.floor(today.getFullYear() / 100);

        // months are 0 indexed in js, and we take 1-indexed months, so we subtract one to get the month
        var month = parseInt(tokenRequest.expirationmonth) - 1;


        var year = parseInt(tokenRequest.expirationyear);

        if (year < 100) {
            year = year + (thisCentury * 100);
        }

        var expiration = new Date(year, month);
        var thisMonth = new Date();
        expiration.setMonth(expiration.getMonth() + 1);

        // check if the card is expired
        if (expiration < thisMonth) {
            validationErrors.push({
                error_code: errorCodes.validation,
                reason: "expirationdate"
            });
            return false;
        }

        return true;
    };

    Validation.validateZipCode = function (tokenRequest, validationErrors) {
        var zipcode = tokenRequest.zipcode;

        if (Validation.isNullOrWhitespace(zipcode)) {
            return true;
        }

        var validZipChars = /^[a-zA-Z0-9 \-]*$/;
        if (!zipcode.match(validZipChars) || zipcode.length > 10) {
            validationErrors.push({
                error_code: errorCodes.validation,
                reason: "zipcode"
            });
            return false;
        }

        return true;
    };

    Validation.validateCardHolder = function (tokenRequest, validationErrors) {
        if (Validation.isNullOrWhitespace(tokenRequest.cardholder)) {
            return false;
        }
        // Up to 4 alphabetical strings seperated by spaces
        if (!tokenRequest.cardholder.match(/^([a-zA-Z]* ?){1,4}$/)) {
            validationErrors.push({
                error_code: errorCodes.validation,
                reason: "cardholder"
            });
            return false;
        }
        return true;
    }

    Validation.validateRequiredFieldHasValue = function (value) {
        return !Validation.isNullOrWhitespace(value);
    };

    Validation.validateTokenRequest = function (tokenRequest) {
        // the list of messages that will be returned by the validation
        var validationErrors = [];

        // fields that must have values
        var requiredFields = ['cardnumber', 'expirationmonth', 'expirationyear', 'cvv'/*, 'cardholder','streetaddress', 'zipcode',*/];

        // validate required fields exist and have values
        for (var i = 0; i < requiredFields.length; i++) {
            if (!tokenRequest.hasOwnProperty(requiredFields[i])) {
                validationErrors.push({ error_code: errorCodes.notFound, reason: requiredFields[i] });
            } else if (!Validation.validateRequiredFieldHasValue(tokenRequest[requiredFields[i]])) {
                validationErrors.push({ error_code: errorCodes.required, reason: requiredFields[i] });
            }
        }

        Validation.validateCardNumber(tokenRequest, validationErrors);
        Validation.validateCVV(tokenRequest, validationErrors);
        Validation.validateExpirationDate(tokenRequest, validationErrors);
        Validation.validateZipCode(tokenRequest, validationErrors);

        return validationErrors;
    };

    // A class responsible for all session-related activities
    var Session = function() { };

    // Generates URL to include merchant details
    Session.generateUrl = function () {
        var params = "?id=" + cayanApiToken;
        return API_PLUS_URL + params;
    }

    // Used to initialise a transaction session
    Session.initialise = function (url, tokenResponse, origSuccessHandler) {

        // Injects an iFrame on to the browser page
        function addDataCollector(merchantId) {
            if (targetDocument.getElementById(iFrameId)) {
                return;
            }

            var params = "?m=" + merchantId + "&s=" + tokenResponse.token;
            var iframe = targetDocument.createElement("iframe");
            iframe.width = 1;
            iframe.height = 1;
            iframe.id = iFrameId;
            iframe.frameBorder = 0;
            iframe.scrolling = "no";
            iframe.onload = function () {
                setTimeout(function() { origSuccessHandler(tokenResponse); }, 100);
            }
            iframe.onerror = function() { origSuccessHandler(tokenResponse); }
            iframe.src = API_PLUS_URL + "/logo.htm" + params;
            iframe.innerHTML = "<img src=\"" + API_PLUS_URL + "/logo.gif" + params + "\" />";
            targetDocument.body.appendChild(iframe);
        }

        // Verifies need for data collection
        function verifyMerchant(sessionResponse) {
            if (!sessionResponse) {
                return;
            }
            var jsonResponse = JSON.parse(sessionResponse);
            if (jsonResponse.hasOwnProperty("merchantId")) {
                var merchantId = jsonResponse.merchantId;
                if (merchantId && tokenResponse.token) {
                    addDataCollector(merchantId);
                }
            }
        }

        // XHR for Chrome/Firefox/Opera/Safari.
        function executeCorsXmlHttpRequest(method) {
            var xhr = new XMLHttpRequest();
            xhr.open(method, url, false);

            xhr.onreadystatechange = function () {
                if (xhr.readyState === XMLHttpRequest.DONE) {
                    if (xhr.status === 200) {
                        var sessionResponse = xhr.responseText;
                        verifyMerchant(sessionResponse);
                    }
                }
            };

            xhr.send();
        }

        // XDomainRequest for IE 8 and 9
        function executeXDomainRequest(method) {
            var xdr = new XDomainRequest();
            xdr.open(method, url, false);

            xdr.onload = function () {
                var sessionResponse = xdr.responseText;
                verifyMerchant(sessionResponse);
            };
            xdr.onerror = function() {}

            xdr.send();
        }

        // Executes the appropriate CORS implementation for the browser.
        function executeCrossDomainRequest(method) {
            var hasXmlHttpRequestCorsSupport = "withCredentials" in (new XMLHttpRequest());
            var hasXDomainRequestSupport = typeof XDomainRequest != "undefined";

            if (hasXmlHttpRequestCorsSupport) {
                executeCorsXmlHttpRequest(method);
            } else if (hasXDomainRequestSupport) {
                executeXDomainRequest(method);
            } else {
                // CORS not supported.
                throw "CORS is not supported by this browser.";
            }
        }

        executeCrossDomainRequest("GET");
    }

    // Tokenization Class - inner class to handle creating token requests and issuing them
    var Tokenization = function () { };

    Tokenization.createTokenRequest = function () {
        var tokenRequest = {};
        tokenRequest.merchantApiKey = cayanApiToken;
        var allInputs = targetDocument.getElementsByTagName('input');
        for (var i = 0; i < allInputs.length; i++) {
            if (allInputs[i].getAttribute("data-cayan") !== null) {
                var type = allInputs[i].getAttribute("data-cayan");
                var value = allInputs[i].value;
                tokenRequest[type] = value;
            }
        }
        var allSelects = targetDocument.getElementsByTagName('select');
        for (var i = 0; i < allSelects.length; i++) {
            if (allSelects[i].getAttribute("data-cayan") !== null) {
                var type = allSelects[i].getAttribute("data-cayan");
                var selectedIndex = allSelects[i].selectedIndex;
                var value = !isNaN(selectedIndex) ? allSelects[i].options[selectedIndex].value : null;
                tokenRequest[type] = value;
            }
        }
        return tokenRequest;
    };

    Tokenization.requestToken = function (tokenRequest, handlers) {

        // Create a wrapper around client's success handler
        function createCayanWrapper() {
            var origSuccessHandler = handlers.success;

            handlers.success = function(tokenResponse) {
                beginSession(tokenResponse, origSuccessHandler);

                // Call the origSuccessHandler if iframe was not appended to Dom
                if (targetDocument.getElementById(iFrameId)) {
                    return;
                }
                origSuccessHandler(tokenResponse);
            }
        }

        // Initialises a transaction session
        function beginSession(tokenResponse, origSuccessHandler) {
            var url = Session.generateUrl();
            Session.initialise(url, tokenResponse, origSuccessHandler);
        }

        function clearSensitiveFields() {
            var allInputs = targetDocument.getElementsByTagName('input');
            for (var i = 0; i < allInputs.length; i++) {
                if (allInputs[i].getAttribute("data-cayan") !== null) {
                    var type = allInputs[i].getAttribute("data-cayan");
                    // clear out cardnumber and cvv after reading them
                    // NOTE this is breaking validation commenting it out for now
                    // if (type == 'cardnumber' || type == 'cvv') {
                    //     allInputs[i].value = '';
                    // }
                }
            }
        };

        function processTokenResponse(response) {

            if (response.errors) {
                var errors = response.errors;
                for (var i = 0; i < errors.length; i++) {
                    errors[i].error_code = errors[i].error_Code;
                    delete errors[i].error_Code;
                }
                handlers.error(errors);
                return;
            }

            var tokenResponse = {
                token: response.token,
                created: response.created,
                expires: response.expires
            };

            clearSensitiveFields();

            handlers.success(tokenResponse);
        }

        // XHR for Chrome/Firefox/Opera/Safari.
        function executeCorsXmlHttpRequest(method, url) {
            var xhr = new XMLHttpRequest();
            xhr.open(method, url, true);
            xhr.onreadystatechange = function () {
                if (xhr.readyState == XMLHttpRequest.DONE) {
                    if (xhr.status == 200) {
                        var response = JSON.parse(xhr.responseText);
                        processTokenResponse(response);
                    } else {
                        var error = { error_code: errorCodes.server, reason: "unavailable" };
                        handlers.error([error]);
                    }
                }
            }; // set request header to application/json
            if (xhr.setRequestHeader) {
                xhr.setRequestHeader("Content-Type", "application/json");
            }
            xhr.send(JSON.stringify(tokenRequest));
        };

        // XDomainRequest for IE 8 and 9
        function executeXDomainRequest(method, url) {

            var xdr = new XDomainRequest();
            xdr.open(method, url);

            xdr.onload = function () {
                var response = JSON.parse(xdr.responseText);
                processTokenResponse(response);
            };
            xdr.onerror = function () {
                var serverErrorResponse = { error_code: errorCodes.server, reason: "unavailable" };
                handlers.error([serverErrorResponse]);
            }; // set request header to text/plain
            if (xdr.setRequestHeader) {
                xdr.setRequestHeader("Content-Type", "text/plain");
            }
            xdr.send(JSON.stringify(tokenRequest));
        }

        // Executes the appropriate CORS implementation for the browser.
        function executeCrossDomainRequest(method, url) {

            var hasXmlHttpRequestCorsSupport = "withCredentials" in (new XMLHttpRequest());
            var hasXDomainRequestSupport = typeof XDomainRequest != "undefined";

            if (hasXmlHttpRequestCorsSupport) {
                executeCorsXmlHttpRequest(method, url);
            } else if (hasXDomainRequestSupport) {
                executeXDomainRequest(method, url);
            } else {
                // CORS not supported.
                throw "CORS is not supported by this browser.";
            }
        }

        createCayanWrapper();

        executeCrossDomainRequest("POST", API_URL);
    };

    // Class that encapsulates public members
    var CayanPublic = function () { };

    /**
    * Sets the client API key
    *
    * @param apiKey - the API Key for this merchant which enables them to use the library.
    */
    CayanPublic.setWebApiKey = function (apiKey) {
        cayanApiToken = apiKey;
    }
    /**
    * Sets the parent document from which the data-cayan input elements are scraped.
    *
    * @param document - the document from which to scrape the input elements.
    */
    CayanPublic.setDocument = function (doc) {
        targetDocument = doc;
    }
    /**
    * Makes a call to the internal card tokenization API to get a payment token by scraping
    * the document for input elements tagged with data-cayan attributes containing the card
    * information. By default, scrapes the window.document object, but the document can be set
    * via the SetDocument() method.
    *
    * @param handlers - An object containing a success handler and an error handler
    */
    CayanPublic.createPaymentToken = function (handlers) {
        if (!cayanApiToken) {
            throw "You must set the API Key using CayanCheckoutPlus.setWebApiKey(yourKey) before requesting tokens";
        }
        var tokenRequest = Tokenization.createTokenRequest();
        var validationErrors = Validation.validateTokenRequest(tokenRequest);
        if (validationErrors.length > 0) {
            if (handlers.hasOwnProperty('error')) {
                handlers.error(validationErrors);
            }
            return;
        }

        Tokenization.requestToken(tokenRequest, handlers);
    }

    /**
    * Restores the cayan library back to its default state.
    */
    CayanPublic.restore = function () {
        cayanApiToken = "";
        targetDocument = window.document;
    }

    return CayanPublic;
})();
