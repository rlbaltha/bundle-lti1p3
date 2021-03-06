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

namespace OAT\Bundle\Lti1p3Bundle\Tests\Functional\Action\Platform\Service;

use Carbon\Carbon;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use OAT\Library\Lti1p3Core\Registration\RegistrationInterface;
use OAT\Library\Lti1p3Core\Registration\RegistrationRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ServiceActionTest extends WebTestCase
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

    public function testItCanHandleLtiServiceRequest(): void
    {
        $credentials = $this->generateCredentials($this->registration, ['scope1', 'scope2']);

        $this->client->request(
            Request::METHOD_GET,
            '/test/service',
            [],
            [],
            ['HTTP_AUTHORIZATION' => sprintf('Bearer %s', $credentials)]
        );

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $responseData = json_decode((string)$response->getContent(), true);
        $this->assertEquals($this->registration->getClientId(), $responseData['claims']['aud']);
        $this->assertEquals($this->registration->getIdentifier(), $responseData['registration']);
        $this->assertEquals(['scope1', 'scope2'], $responseData['roles']);
        $this->assertEquals($credentials, $responseData['credentials']);
        $this->assertEquals(
            [
                'JWT access token is not expired',
                'Registration found for client_id: client_id',
                'Platform key chain found for registration: testRegistration',
                'JWT access token signature is valid'
            ],
            $responseData['validations']['successes']
        );
        $this->assertNull($responseData['validations']['error']);
    }

    public function testItReturnsUnauthorizedResponseWithoutBearer(): void
    {
        $this->client->request(Request::METHOD_GET, '/test/service');

        $response = $this->client->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testItReturnsUnauthorizedResponseWithInvalidBearer(): void
    {
        $this->client->request(
            Request::METHOD_GET,
            '/test/service',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer invalid']
        );

        $response = $this->client->getResponse();
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    private function generateCredentials(RegistrationInterface $registration, array $scopes = []): string
    {
        $now = Carbon::now();

        return (new Builder())
            ->permittedFor($audience ?? $registration->getClientId())
            ->identifiedBy(uniqid())
            ->issuedAt($now->getTimestamp())
            ->expiresAt($now->addSeconds(3600)->getTimestamp())
            ->withClaim('scopes', $scopes)
            ->getToken(new Sha256(), $registration->getPlatformKeyChain()->getPrivateKey())
            ->__toString();
    }
}
