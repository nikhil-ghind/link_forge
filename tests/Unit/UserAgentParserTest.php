<?php

namespace Tests\Unit;

use App\Services\UserAgentParser;
use PHPUnit\Framework\TestCase;

class UserAgentParserTest extends TestCase
{
    private UserAgentParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new UserAgentParser;
    }

    public function test_it_classifies_desktop_chrome(): void
    {
        $result = $this->parser->parse(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'
        );

        $this->assertSame('desktop', $result['device_type']);
        $this->assertSame('Chrome', $result['browser']);
        $this->assertSame('Windows', $result['os']);
        $this->assertFalse($result['is_bot']);
    }

    public function test_it_prefers_edge_over_the_chrome_token_it_also_advertises(): void
    {
        $result = $this->parser->parse(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 Edg/124.0'
        );

        $this->assertSame('Edge', $result['browser']);
    }

    public function test_it_classifies_ios_safari_as_mobile(): void
    {
        $result = $this->parser->parse(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1'
        );

        $this->assertSame('mobile', $result['device_type']);
        $this->assertSame('Safari', $result['browser']);
        $this->assertSame('iOS', $result['os']);
    }

    public function test_it_classifies_ipad_as_tablet(): void
    {
        $result = $this->parser->parse(
            'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/604.1'
        );

        $this->assertSame('tablet', $result['device_type']);
    }

    public function test_android_without_mobile_token_is_a_tablet(): void
    {
        $result = $this->parser->parse(
            'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'
        );

        $this->assertSame('tablet', $result['device_type']);
        $this->assertSame('Android', $result['os']);
    }

    public function test_it_flags_crawlers_and_tooling(): void
    {
        foreach ([
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'curl/8.4.0',
            'python-requests/2.31.0',
            'Slackbot-LinkExpanding 1.0',
        ] as $ua) {
            $result = $this->parser->parse($ua);

            $this->assertTrue($result['is_bot'], "expected bot for: {$ua}");
            $this->assertSame('bot', $result['device_type']);
        }
    }

    public function test_missing_user_agent_is_treated_as_automation(): void
    {
        $result = $this->parser->parse(null);

        $this->assertTrue($result['is_bot']);
        $this->assertSame('other', $result['device_type']);
        $this->assertNull($result['browser']);
    }
}
