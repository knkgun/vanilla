<?php
/**
 * @author David Barbier <david.barbier@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license GPLv2
 */

namespace VanillaTests\QnA;

use Vanilla\QnA\Models\AnswerModel;
use VanillaTests\APIv2\QnaApiTestTrait;
use VanillaTests\SetupTraitsTrait;
use VanillaTests\SiteTestTrait;
use VanillaTests\EventSpyTestTrait;

use Garden\Events\ResourceEvent;

use VanillaTests\VanillaTestCase;

/**
 * Test QnA events are working.
 */
class QnAEventsTest extends VanillaTestCase {
    use SiteTestTrait, SetupTraitsTrait, EventSpyTestTrait, QnaApiTestTrait;

    /** @var AnswerModel */
    private $answerModel;

    /** @var int */
    private $categoryID;

    /** @var \DiscussionModel */
    private $discussionModel;

    /** @var \QnAPlugin */
    private $plugin;

    /**
     * Get the names of addons to install.
     *
     * @return string[] Returns an array of addon names.
     */
    protected static function getAddons(): array {
        return ["vanilla", "qna"];
    }

    /**
     * Instantiate fixtures.
     */
    public function setUp(): void {
        parent::setUp();
        $this->setUpTestTraits();
        $this->enableCaching();

        $this->categoryID = $this->createCategory()["categoryID"];

        $this->container()->call(function (\QnAPlugin $plugin, AnswerModel $answerModel, \DiscussionModel $discussionModel) {
            $this->answerModel = $answerModel;
            $this->discussionModel = $discussionModel;
            $this->plugin = $plugin;
        });
    }

    /**
     * This method is called before the first test of this test class is run.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::setUpBeforeClassTestTraits();
    }

    /**
     * Tests event upon an answer to a question is chosen.
     */
    public function testQnaChosenAnswerEvent() {
        $question = $this->createQuestion([
            'categoryID' => $this->categoryID,
            'name' => 'Question 1',
            'body' => 'Question 1'
        ]);


        $answer1 = $this->createAnswer([
            'discussionID' => $question['discussionID'],
            'name' => 'Answer 1',
            'body' => 'Answer 1'
        ]);

        $answer2 = $this->createAnswer([
            'discussionID' => $question['discussionID'],
            'name' => 'Answer 2',
            'body' => 'Answer 2'
        ]);

        $answer3 = $this->createAnswer([
            'discussionID' => $question['discussionID'],
            'name' => 'Answer 3',
            'body' => 'Answer 3'
        ]);

        $this->answerModel->updateCommentQnA($question, $answer2, 'Accepted');

        $this->assertEventDispatched(
            $this->expectedResourceEvent(
                'answer',
                ResourceEvent::ACTION_UPDATE,
                [
                    'commentID' => $answer2['commentID'],
                    'discussionID' => $question['discussionID'],
                    'qnA' => "Accepted"
                ]
            )
        );
    }

    /**
     * Verify basic functionality of count limiting for unanswered questions.
     */
    public function testUnansweredLimit(): void {
        $previousLimit = $this->plugin->getUnansweredCountLimit();
        try {
            $limit = 3;
            $this->plugin->setUnansweredCountLimit($limit);
            $this->createQuestion();
            $this->createQuestion();
            $this->createQuestion();
            $result = $this->bessy()->getJsonData("/discussions/unansweredcount");
            $this->assertSame("{$limit}+", $result["UnansweredCount"]);
        } finally {
            $this->plugin->setUnansweredCountLimit($previousLimit);
        }
    }
}
