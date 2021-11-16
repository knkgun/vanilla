<?php
/**
 * @copyright 2009-2021 Vanilla Forums Inc.
 * @license GPL-2.0-only
 */

namespace VanillaTests\Library\EmbeddedContent\Factories;

use Garden\Http\HttpResponse;
use Vanilla\EmbeddedContent\Embeds\KalturaEmbed;
use Vanilla\EmbeddedContent\Factories\KalturaEmbedFactory;
use VanillaTests\MinimalContainerTestCase;
use VanillaTests\Fixtures\MockHttpClient;

/**
 * Tests for the embed and factory.
 */
class KalturaEmbedFactoryTest extends MinimalContainerTestCase {

    /** @var KalturaEmbedFactory */
    private $factory;

    /** @var MockHttpClient */
    private $httpClient;

    /**
     * Set the factory and client.
     */
    public function setUp(): void {
        parent::setUp();
        $this->httpClient = new MockHttpClient();
        $this->factory = new KalturaEmbedFactory($this->httpClient);
    }

    /**
     * Test that all expected domains are supported.
     *
     * @param string $urlToTest
     * @dataProvider supportedDomainsProvider
     */
    public function testSupportedDomains(string $urlToTest) {
        $this->assertTrue($this->factory->canHandleUrl($urlToTest));
    }

    /**
     * @return array
     */
    public function supportedDomainsProvider(): array {
        return [
            ["https://9008.mediaspace.kaltura.com/id/0_osb0x29b"],
        ];
    }

    /**
     * Test network request fetching and handling.
     *
     * @param string $urlToTest
     * @dataProvider supportedDomainsProvider
     */
    public function testCreateEmbedForUrl(string $urlToTest) {
        $oembedUrl = $this->factory->getOEmbedUrl($urlToTest);

        // phpcs:disable Generic.Files.LineLength
        $data = [
            "entryId" => "0_osb0x29b",
            "version" => "1.0",
            "type" => "video",
            "provider_url" => "https://9008.mediaspace.kaltura.com/",
            "provider_name" => "1234578change1",
            "title" => "Roar.mp4 - Shwiz",
            "width" => "400",
            "height" => "285",
            "playerId" => 29387141,
            "thumbnail_height" => "285",
            "thumbnail_width" => "400",
            "thumbnail_url" => "https://cfvod.kaltura.com/p/9008/sp/900800/thumbnail/entry_id/0_osb0x29b/version/100002/width/400/height/285",
            "author_name" => "gonen.radai",
            "html" =>
                '<iframe ' .
                    'id="kaltura_player" ' .
                    'src="https://cdnapi.kaltura.com/p/9008/sp/900800/embedIframeJs/uiconf_id/35650011/partner_id/9008?iframeembed=true&playerId=kaltura_player&entry_id=0_osb0x29b&flashvars[leadWithHTML5]=true&flashvars[streamerType]=auto&flashvars[localizationCode]=en&flashvars[loadThumbnailWithKs]=true&flashvars[loadThumbnailsWithReferrer]=true&flashvars[expandToggleBtn.plugin]=false&flashvars[sideBarContainer.plugin]=true&flashvars[sideBarContainer.position]=left&flashvars[sideBarContainer.clickToClose]=true&flashvars[chapters.plugin]=true&flashvars[chapters.layout]=vertical&flashvars[chapters.thumbnailRotator]=false&flashvars[streamSelector.plugin]=true&flashvars[EmbedPlayer.SpinnerTarget]=videoHolder&flashvars[dualScreen.plugin]=true&flashvars[hotspots.plugin]=1&flashvars[Kaltura.addCrossoriginToIframe]=true&wid=1_i0c2o2q5" ' .
                    'width="400" ' .
                    'height="285" ' .
                    'allowfullscreen ' .
                    'webkitallowfullscreen ' .
                    'mozAllowFullScreen ' .
                    'allow="autoplay *; fullscreen *; encrypted-media *" ' .
                    'sandbox="allow-forms allow-same-origin allow-scripts allow-top-navigation allow-pointer-lock allow-popups allow-modals allow-orientation-lock allow-popups-to-escape-sandbox allow-presentation allow-top-navigation-by-user-activation" ' .
                    'frameborder="0" ' .
                    'title="Kaltura Player">' .
                '</iframe>'
        ];
        // phpcs:enable Generic.Files.LineLength

        $this->httpClient->addMockResponse(
            $oembedUrl,
            new HttpResponse(
                200,
                "Content-Type: application/json",
                json_encode($data)
            )
        );

        $embed = $this->factory->createEmbedForUrl($urlToTest);
        $frameSrc = $this->factory->getIframeSrcFromHtml($data["html"]);
        $embedData = $embed->jsonSerialize();
        $this->assertEquals(
            [
                "height" => $data["height"],
                "width" => $data["width"],
                "photoUrl" => $data['thumbnail_url'],
                "url" => $urlToTest,
                "embedType" => KalturaEmbed::TYPE,
                "name" => $data["title"],
                "frameSrc" => $frameSrc,
            ],
            $embedData,
            "Data can be fetched over the network to create the embed from a URL."
        );

        // Just verify that this doesn't throw an exception.
        $dataEmbed = new KalturaEmbed($embedData);
        $this->assertInstanceOf(KalturaEmbed::class, $dataEmbed);
    }

    /**
     * Test error 401 upon embed creation.
     */
    public function testCreateEmbed401Error() {
        $url = "https://mediaspace.kaltura.com/id/2_q60gw8vsbte";
        $oembedUrl = $this->factory->getOEmbedUrl($url);
        $this->httpClient->addMockResponse(
            $oembedUrl,
            new HttpResponse(
                401,
                "Content-Type: application/json",
                json_encode([])
            )
        );

        $this->expectExceptionMessage('You are not authorized to access this URL.');
        $this->factory->createEmbedForUrl($url);
    }

    /**
     * Test error 500 upon embed creation.
     */
    public function testCreateEmbed500Error() {
        $url = "https://mediaspace.kaltura.com/id/1_p59fv7urasd";
        $oembedUrl = $this->factory->getOEmbedUrl($url);
        $this->httpClient->addMockResponse(
            $oembedUrl,
            new HttpResponse(
                500,
                "Content-Type: application/json",
                json_encode(['error' => ['message' => 'Unable to resolve host.']])
            )
        );

        $this->expectExceptionMessage('Unable to resolve host.');
        $this->factory->createEmbedForUrl($url);
    }

    /**
     * Test error 406 upon embed creation.
     */
    public function testCreateEmbed406Error() {
        $url = "https://mediaspace.valdosta.edu/id/1_0wsxajr";
        $oembedUrl = $this->factory->getOEmbedUrl($url);
        $this->httpClient->addMockResponse(
            $oembedUrl,
            new HttpResponse(
                200,
                "Content-Type: application/json",
                // We have an html response.
                json_encode("<!DOCTYPE html><html><head></head><body>Hey, I'm the body</body></html>")
            )
        );

        $this->expectExceptionMessage('URL did not result in a JSON type response.');
        $this->factory->createEmbedForUrl($url);
    }
}
