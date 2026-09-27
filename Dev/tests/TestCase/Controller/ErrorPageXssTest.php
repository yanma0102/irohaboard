<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Error Page XSS Regression Test
 *
 * Verifies that error pages (error400.php) do not output
 * user-controlled URL values without HTML escaping.
 *
 * Related: D-17 (XSS in error400.php $url)
 */
class ErrorPageXssTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * API endpoint with non-numeric ID should return JSON 404 (not HTML).
     * After D-31 fix, InvalidParameterException is caught by ApiErrorMiddleware
     * and returned as JSON — never reaching error400.php.
     */
    public function testApiNonNumericIdReturnsJsonNotHtml(): void
    {
        $this->get('/api/v1/contents/<script>alert(1)</script>');

        // Should be 404 from ApiErrorMiddleware (JSON), not HTML error page
        $this->assertResponseCode(404);

        $body = (string)$this->_response->getBody();
        // Must be valid JSON — not HTML containing unescaped script tags
        $this->assertJson($body);
        $this->assertStringNotContainsString('<script>', $body);
    }

    /**
     * API users endpoint with non-numeric ID also returns JSON.
     */
    public function testApiUsersNonNumericIdReturnsJson(): void
    {
        $this->get('/api/v1/users/<script>alert(1)</script>');

        $this->assertResponseCode(404);

        $body = (string)$this->_response->getBody();
        $this->assertJson($body);
        $this->assertStringNotContainsString('<script>', $body);
    }

    /**
     * API courses endpoint with non-numeric ID also returns JSON.
     */
    public function testApiCoursesNonNumericIdReturnsJson(): void
    {
        $this->get('/api/v1/courses/<script>alert(1)</script>');

        $this->assertResponseCode(404);

        $body = (string)$this->_response->getBody();
        $this->assertJson($body);
        $this->assertStringNotContainsString('<script>', $body);
    }

    /**
     * API records endpoint with non-numeric ID also returns JSON.
     */
    public function testApiRecordsNonNumericIdReturnsJson(): void
    {
        $this->get('/api/v1/records/<script>alert(1)</script>');

        $this->assertResponseCode(404);

        $body = (string)$this->_response->getBody();
        $this->assertJson($body);
        $this->assertStringNotContainsString('<script>', $body);
    }

    /**
     * API groups endpoint with non-numeric ID also returns JSON.
     */
    public function testApiGroupsNonNumericIdReturnsJson(): void
    {
        $this->get('/api/v1/groups/<script>alert(1)</script>');

        $this->assertResponseCode(404);

        $body = (string)$this->_response->getBody();
        $this->assertJson($body);
        $this->assertStringNotContainsString('<script>', $body);
    }

    /**
     * Non-existent API path returns JSON error (not HTML error page).
     */
    public function testNonExistentApiPathReturnsJson(): void
    {
        $this->get('/api/v1/__nonexistent_xss_test__');

        $this->assertResponseCode(404);

        $body = (string)$this->_response->getBody();
        $this->assertJson($body);
        $this->assertStringNotContainsString('<script>', $body);
    }
}
