<?php
/**
 * @author Richard Flynn <rflynn@higherlogic.com>
 * @copyright 2009-2021 Vanilla Forums Inc.
 * @license GPL-2.0-only
 */

namespace Vanilla\Analytics;

use CategoryModel;
use CommentModel;
use DiscussionModel;
use Gdn;
use PHPUnit\Exception;
use UserModel;
use Vanilla\Utility\ArrayUtils;

/**
 * Utility functions for Trackable Events.
 */
class TrackableCommunityModel {

    /** @var DiscussionModel */
    private $discussionModel;

    /** @var TrackableUserModel */
    private $userUtils;

    /** @var CommentModel */
    private $commentModel;

    /**
     * DI.
     *
     * @param DiscussionModel $discussionModel
     * @param TrackableUserModel $userUtils
     * @param CommentModel $commentModel
     */
    public function __construct(DiscussionModel $discussionModel, TrackableUserModel $userUtils, CommentModel $commentModel) {
        $this->discussionModel = $discussionModel;
        $this->userUtils = $userUtils;
        $this->commentModel = $commentModel;
    }

    /**
     * Grab basic information about a category, based on a category ID.
     *
     * @param int $categoryID The target category's integer ID.
     * @return array An array representing basic category data.
     */
    public function getTrackableCategory(int $categoryID): array {
        $categoryDetails = CategoryModel::categories($categoryID);
        if ($categoryDetails) {
            $category = [
                'categoryID' => $categoryDetails['CategoryID'],
                'name' => $categoryDetails['Name'],
                'slug' => $categoryDetails['UrlCode'],
                "url" => categoryUrl($categoryDetails),
            ];
        } else {
            // Fallback category data
            $category = ['categoryID' => 0];
        }

        return $category;
    }

    /**
     * Fetch all ancestors up to, and including, the current category.
     *
     * @param int $categoryID ID of the category we're tracking down the ancestors of.
     * @return array An array of objects containing the ID and name of each of the category's ancestors.
     */
    public static function getCategoryAncestors(int $categoryID): array {
        $ancestors = [];

        // Grab our category's ancestors, which include the current category.
        $categories = CategoryModel::getAncestors($categoryID, false, true);

        $categoryLevel = 0;
        foreach ($categories as $currentCategory) {
            $categoryLabel = 'cat' . sprintf('%02d', ++$categoryLevel);

            $ancestors[$categoryLabel] = [
                'categoryID' => (int)$currentCategory['CategoryID'],
                'name' => $currentCategory['Name'],
                'slug' => $currentCategory['UrlCode']
            ];
        }

        return $ancestors;
    }

    /**
     * Get a discussion with special fields used for tracking.
     *
     * @param int|array $discussionOrDiscussionID
     * @return array
     */
    public function getTrackableDiscussion($discussionOrDiscussionID): array {
        if (is_int($discussionOrDiscussionID)) {
            $discussion = $this->discussionModel->getID($discussionOrDiscussionID, DATASET_TYPE_ARRAY);
            if (empty($discussion)) {
                return [
                    'discussionID' => 0,
                ];
            }
            $discussion = $this->discussionModel->normalizeRow($discussion);
        } else {
            $discussion = $discussionOrDiscussionID;
        }
        $schema = $this->discussionModel->schema();
        $firstCommentID = $discussion['firstCommentID'] ?? $discussion['FirstCommentID'] ?? null;
        $discussion = $schema->validate($discussion);

        $discussion['firstCommentID'] = $firstCommentID;
        $discussion['category'] = $this->getTrackableCategory($discussion['discussionID']);
        $discussion['categoryAncestors'] = self::getCategoryAncestors($discussion['categoryID']);
        $discussion['groupID'] = $discussion['groupID'] ?? null;
        $discussion['commentMetric'] = [
            'firstComment' => false,
            'time' => null
        ];
        $discussion['countComments'] = (int)$discussion['countComments'];
        $discussion['dateInserted'] = TrackableDateUtils::getDateTime($discussion['dateInserted']);
        $discussion['discussionUser'] = $this->userUtils->getTrackableUser($discussion['insertUserID']);

        // Tracking events don't need the body. It takes up a lot of space unnecessarily.
        $discussion['body'] = null;
        return $discussion;
    }

    /**
     * Grab standard data for a comment.
     *
     * @param int|array $commentOrCommentID A comment's unique ID, used to query data.
     * @param string $type Event type (e.g. comment_add or comment_edit).
     * @return array Array representing comment row on success, false on failure.
     */
    public function getTrackableComment($commentOrCommentID, string $type = 'comment_add'): array {
        if (is_int($commentOrCommentID)) {
            $comment = $this->commentModel->getID($commentOrCommentID);
            if (empty($comment)) {
                return [
                    'commentID' => 0,
                ];
            }
            $comment = $this->commentModel->normalizeRow($comment);
        } else {
            $comment = $commentOrCommentID;
        }

        $commentSchema = $this->commentModel->schema();
        $comment = $commentSchema->validate($comment);

        $data = [
            'commentID' => (int) $comment['commentID'],
            'dateInserted' => TrackableDateUtils::getDateTime($comment['dateInserted']),
            'discussionID' => (int) $comment['discussionID'],
            'insertUser' => $this->userUtils->getTrackableUser($comment['insertUserID'])
        ];

        try {
            $discussion = $this->getTrackableDiscussion($comment['discussionID']);
        } catch (\Exception $ex) {
            $discussion = false;
        }

        if ($discussion) {
            $commentNumber = val('countComments', $discussion, 0);

            $data['category'] = val('category', $discussion);
            $data['categoryAncestors'] = val('categoryAncestors', $discussion);
            $data['discussionUser'] = val('discussionUser', $discussion);

            $timeSinceDiscussion = $data['dateInserted']['timestamp'] - $discussion['dateInserted']['timestamp'];
            $data['commentMetric'] = [
                'firstComment' => $data['commentID'] === $discussion['firstCommentID'] ? true : false,
                'time' => (int)$timeSinceDiscussion
            ];

            // The count of comments we get from the discussion doesn't include this one, so we compensate if it's an add.
            if ($type === 'comment_add') {
                $commentPosition = ($commentNumber);
                $data['commentPosition'] = $commentPosition;
            }

            // If it's a delete, decrement the countComments number.
            if ($type === 'comment_delete') {
                $data['commentPosition'] = 0;
            }

            if ($type === 'comment_edit') {
                $data['commentPosition'] = $commentNumber;
            }

            // Removing those redundancies...
            unset(
                $discussion['category'],
                $discussion['categoryAncestors'],
                $discussion['commentMetric'],
                $discussion['discussionUser'],
                $discussion['record']
            );

            // The body is large and unnecessary.
            $data['body'] = null;

            $data['discussion'] = $discussion;
        }

        return $data;
    }
}
