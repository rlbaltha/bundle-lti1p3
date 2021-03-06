# LTI Service - Platform

> How to use the bundle to make your application act as a platform in the context of [LTI services](http://www.imsglobal.org/spec/lti/v1p3/#interacting-with-services).

## Table of contents

- [Configuring OAuth2 server](#configuring-oauth2-server)
- [Protecting platform service endpoints](#protecting-platform-service-endpoints)

## Configuring platform OAuth2 server

The [OAuth2AccessTokenCreationAction](../../Action/Platform/Service/OAuth2AccessTokenCreationAction.php) is automatically added to your application via the related [flex recipe](https://github.com/symfony/recipes-contrib/tree/master/oat-sa/bundle-lti1p3), in file `config/routes/lti1p3.yaml`.

**Default route**: `[POST] '/lti1p3/auth/{keyChainIdentifier}/token'`

This endpoint:
- allows tools to get granted to call your platform services endpoints, by following the [client_credentials grant type with assertion](https://www.imsglobal.org/spec/security/v1p0/#using-json-web-tokens-with-oauth-2-0-client-credentials-grant). 
- is working for a defined `keyChainIdentifier` as explained [here](../message/platform.md), so you can expose several of them if your application is acting as several deployed platforms

If you configure a key chain as following:

```yaml
# config/packages/lti1p3.yaml
lti1p3:
    key_chains:
        platformKey:
            key_set_name: "platformSet"
            public_key: "file://path/to/public.key"
            private_key: "file://path/to/private.key"
            private_key_passphrase: 'someSecretPassPhrase'
```

You can then configure a platform as following (using the key chain identifier `platformKey`):

```yaml
# config/packages/lti1p3.yaml
lti1p3:
    platforms:
        myPlatform:
            name: "My Platform"
            audience: "http://example.com/platform"
            oidc_authentication_url: "http://example.com/lti1p3/oidc/login-authentication"
            oauth2_access_token_url: "http://example.com/lti1p3/auth/platformKey/token"
```

Once set up, tools can request access tokens by following the [client_credentials grant type with assertion](https://www.imsglobal.org/spec/security/v1p0/#using-json-web-tokens-with-oauth-2-0-client-credentials-grant):
- `grant_type`: `client_credentials`
- `client_assertion_type`: `urn:ietf:params:oauth:client-assertion-type:jwt-bearer`
- `client_assertion`: the tool's generated JWT assertion
- `scope`: `https://purl.imsglobal.org/spec/lti-ags/scope/lineitem https://purl.imsglobal.org/spec/lti-ags/scope/result/read`

Request example:

```shell script
POST /lti1p3/auth/platformKey/token HTTP/1.1
Host: example.com
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials
&client_assertion_type=urn%3Aietf%3Aparams%3Aoauth%3Aclient-assertion-type%3Ajwt-bearer
&client_assertion=eyJ0eXAiOi....
&scope=http%3A%2F%2Fimsglobal.org%2Fspec%2Flti-ags%2Fscope%2Flineitem%20http%3A%2F%2Fimsglobal.org%2Fspec%2Flti-ags%2Fscope%2Fresult%2Fread 
```

As a response, the [OAuth2AccessTokenCreationAction](../../Action/Platform/Service/OAuth2AccessTokenCreationAction.php) will offer an access token (following OAuth2 standards), valid for `3600 seconds`:

```shell script
HTTP/1.1 200 OK
Content-Type: application/json;charset=UTF-8
Cache-Control: no-store
Pragma: no-cache

{
    "access_token" : "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1N.....",
    "token_type" : "bearer",
    "expires_in" : 3600,
    "scope" : "https://purl.imsglobal.org/spec/lti-ags/scope/lineitem https://purl.imsglobal.org/spec/lti-ags/scope/result/read"    
} 
```

**Notes**:
- a `HTTP 401` response if the client assertion cannot match a registered tool.
- to automate (and cache) authentication grants from the tools side, a [ServiceClient](https://github.com/oat-sa/lib-lti1p3-core/blob/master/src/Service/Client/ServiceClient.php) is ready to use for your LTI service calls as explained [here](tool.md)

## Protecting platform service endpoints

Considering you have the following platform service endpoint:

```yaml
#config/routes.yaml
platform_service:
    path: /platform/service
    controller: App\Action\Platform\Service\LtiServiceAction
```

To protect your endpoint, this bundle provides the `lti1p3_service` [security firewall](../../Security/Firewall/Service/LtiServiceAuthenticationListener.php) to put in front of your routes:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        lti1p3_service:
            pattern: ^/platform/service
            stateless: true
            lti1p3_service: true
```

It will automatically handle the provided access token authentication, and add a [LtiServiceToken](../../Security/Authentication/Token/Service/LtiServiceToken.php) in the [security token storage](https://symfony.com/doc/current/security.html), that you can use to retrieve your authentication context.

For example:

```php
<?php

declare(strict_types=1);

namespace App\Action\Platform\Service;

use OAT\Bundle\Lti1p3Bundle\Security\Authentication\Token\Service\LtiServiceToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Security;

class LtiServiceAction
{
    /** @var Security */
    private $security;

    public function __construct(Security $security)
    {
        $this->security = $security;
    }

    public function __invoke(Request $request): Response
    {
        /** @var LtiServiceToken $token */
        $token = $this->security->getToken();

        // Related registration
        $registration = $token->getRegistration();

        // Related access token
        $token = $token->getAccessToken();

        // Related scopes
        $scopes = $token->getScopes();

        // You can even access validation results
        $validationResults = $token->getValidationResult();

        // Your service endpoint logic ...

        return new Response(...);
    }
}
```
