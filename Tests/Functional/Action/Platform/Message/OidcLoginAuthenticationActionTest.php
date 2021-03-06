<?php

/**
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public License
 * as published by the Free Software Foundation; under version 2
 * of the License (non-upgradable).
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * Copyright (c) 2020 (original work) Open Assessment Technologies SA;
 */

declare(strict_types=1);

namespace OAT\Bundle\Lti1p3Bundle\Tests\Functional\Action\Platform\Message;

use Lcobucci\JWT\Parser;
use OAT\Library\Lti1p3Core\Launch\Builder\OidcLaunchRequestBuilder;
use OAT\Library\Lti1p3Core\Launch\Request\OidcLaunchRequest;
use OAT\Library\Lti1p3Core\Link\ResourceLink\ResourceLink;
use OAT\Library\Lti1p3Core\Message\LtiMessage;
use OAT\Library\Lti1p3Core\Message\LtiMessageInterface;
use OAT\Library\Lti1p3Core\Registration\RegistrationInterface;
use OAT\Library\Lti1p3Core\Registration\RegistrationRepositoryInterface;
use OAT\Library\Lti1p3Core\Security\Jwt\AssociativeDecoder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OidcLoginAuthenticationActionTest extends WebTestCase
{
    /** @var KernelBrowser */
    private $client;

    /** @var RegistrationInterface */
    private $registration;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $this->registration = static::$container
            ->get(RegistrationRepositoryInterface::class)
            ->find('testRegistration');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->client->getCrawler()->clear();
    }

    public function testValidLoginAuthenticationWithPostMethod(): void
    {
        /** @var OidcLaunchRequest $oidcLaunchRequest */
        $oidcLaunchRequest = static::$container->get(OidcLaunchRequestBuilder::class)->buildResourceLinkOidcLaunchRequest(
            new ResourceLink('resourceLinkIdentifier'),
            $this->registration,
            'loginHint'
        );

        $this->client->request(
            Request::METHOD_POST,
            '/lti1p3/oidc/login-authentication',
            [
                'scope' => 'openid',
                'response_type' => 'id_token',
                'client_id' => $this->registration->getClientId(),
                'redirect_uri' => $this->registration->getTool()->getLaunchUrl(),
                'login_hint' => 'loginHint',
                'state' => 'state',
                'response_mode' => 'form_post',
                'nonce' => 'nonce',
                'prompt' => 'none',
                'lti_message_hint' => $oidcLaunchRequest->getLtiMessageHint(),
                'lti_deploymentId' => $oidcLaunchRequest->getLtiDeploymentId(),
            ]
        );

        $this->assertLoginAuthenticationResponse($this->client->getResponse());
    }

    public function testValidLoginAuthenticationWithGetMethod(): void
    {
        /** @var OidcLaunchRequest $oidcLaunchRequest */
        $oidcLaunchRequest = static::$container
            ->get(OidcLaunchRequestBuilder::class)
            ->buildResourceLinkOidcLaunchRequest(
                new ResourceLink('resourceLinkIdentifier'),
                $this->registration,
                'loginHint'
            );

        $this->client->request(
            Request::METHOD_GET,
            sprintf(
                '/lti1p3/oidc/login-authentication?%s',
                http_build_query([
                    'scope' => 'openid',
                    'response_type' => 'id_token',
                    'client_id' => $this->registration->getClientId(),
                    'redirect_uri' => $this->registration->getTool()->getLaunchUrl(),
                    'login_hint' => 'loginHint',
                    'state' => 'state',
                    'response_mode' => 'form_post',
                    'nonce' => 'nonce',
                    'prompt' => 'none',
                    'lti_message_hint' => $oidcLaunchRequest->getLtiMessageHint(),
                    'lti_deploymentId' => $oidcLaunchRequest->getLtiDeploymentId(),
                ])
            )
        );

        $this->assertLoginAuthenticationResponse($this->client->getResponse());
    }

    public function testLoginAuthenticationWithInvalidLtiMessageHint(): void
    {
        $this->client->request(
            Request::METHOD_POST,
            '/lti1p3/oidc/login-authentication',
            [
                'scope' => 'openid',
                'response_type' => 'id_token',
                'client_id' => $this->registration->getClientId(),
                'redirect_uri' => $this->registration->getTool()->getLaunchUrl(),
                'login_hint' => 'loginHint',
                'state' => 'state',
                'response_mode' => 'form_post',
                'nonce' => 'nonce',
                'prompt' => 'none',
                'lti_message_hint' => 'invalid',
            ]
        );

        $response = $this->client->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $this->assertStringContainsString('OIDC login authentication failed', (string)$response->getContent());
    }

    private function assertLoginAuthenticationResponse(Response $response): void
    {
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $crawler = $this->client->getCrawler();

        $this->assertEquals(
            $this->registration->getTool()->getLaunchUrl(),
            $crawler->filterXPath('//body/form')->attr('action')
        );

        $this->assertEquals(
            'state',
            $crawler->filterXPath('//body/form/input[@name="state"]')->attr('value')
        );

        $ltiMessage = new LtiMessage((new Parser(new AssociativeDecoder()))->parse(
            $crawler->filterXPath('//body/form/input[@name="id_token"]')->attr('value')
        ));

        $this->assertEquals(LtiMessageInterface::LTI_VERSION, $ltiMessage->getVersion());
        $this->assertEquals('resourceLinkIdentifier', $ltiMessage->getResourceLink()->getId());
        $this->assertEquals('loginHint', $ltiMessage->getUserIdentity()->getIdentifier());
    }
}
