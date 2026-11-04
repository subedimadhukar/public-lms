<?php
/**
 * Tests for video URL parsing.
 *
 * @package PublicLMS
 */

namespace PublicLMS\Tests\Blocks;

use PublicLMS\Blocks\VideoSource;
use WP_UnitTestCase;

/**
 * @coversDefaultClass \PublicLMS\Blocks\VideoSource
 */
class VideoSourceTest extends WP_UnitTestCase {

	/**
	 * @covers ::parse
	 * @covers ::detect
	 */
	public function test_youtube_urls_become_privacy_enhanced_embeds() {
		foreach ( array(
			'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s',
			'https://youtu.be/dQw4w9WgXcQ',
			'https://www.youtube.com/embed/dQw4w9WgXcQ',
			'https://youtube.com/shorts/dQw4w9WgXcQ',
			'dQw4w9WgXcQ',
		) as $url ) {
			$video = VideoSource::parse( '', $url );
			$this->assertNotNull( $video, $url );
			$this->assertSame( 'youtube', $video['provider'] );
			$this->assertSame( 'dQw4w9WgXcQ', $video['id'] );
			$this->assertStringStartsWith( 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?', $video['url'] );
			$this->assertStringContainsString( 'enablejsapi=1', $video['url'] );
		}
		$this->assertNull( VideoSource::parse( 'youtube', 'https://www.youtube.com/channel/abc' ) );
	}

	/**
	 * @covers ::parse
	 */
	public function test_vimeo_keeps_the_unlisted_hash_and_sets_do_not_track() {
		$video = VideoSource::parse( '', 'https://vimeo.com/76979871/8272103f6e' );
		$this->assertSame( 'vimeo', $video['provider'] );
		$this->assertSame( '76979871', $video['id'] );
		$this->assertStringContainsString( 'h=8272103f6e', $video['url'] );
		$this->assertStringContainsString( 'dnt=1', $video['url'] );
		$this->assertSame( 'https://player.vimeo.com', $video['origin'] );
		$this->assertSame( '1234', VideoSource::parse( 'vimeo', 'https://player.vimeo.com/video/1234?h=abcdef12' )['id'] );
	}

	/**
	 * @covers ::parse
	 */
	public function test_bunny_stream_play_and_embed_urls() {
		$guid  = '0b5d1f7e-1f2a-4a3b-9c8d-123456789abc';
		$video = VideoSource::parse( '', 'https://iframe.mediadelivery.net/play/12345/' . $guid );
		$this->assertSame( 'bunny', $video['provider'] );
		$this->assertStringStartsWith( 'https://iframe.mediadelivery.net/embed/12345/' . $guid, $video['url'] );
		$this->assertNull( VideoSource::parse( 'bunny', 'https://example.com/embed/1/2' ) );
	}

	/**
	 * @covers ::parse
	 */
	public function test_self_hosted_files_need_an_http_url() {
		$this->assertSame( 'self', VideoSource::detect( 'https://example.com/uploads/intro.mp4' ) );
		$this->assertSame( 'https://example.com/intro.webm', VideoSource::parse( 'self', 'https://example.com/intro.webm' )['url'] );
		$this->assertNull( VideoSource::parse( 'self', 'javascript:alert(1)' ) );
		$this->assertNull( VideoSource::parse( '', 'https://example.com/page' ) );
		$this->assertNull( VideoSource::parse( '', '' ) );
	}
}
