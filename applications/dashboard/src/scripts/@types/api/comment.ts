/**
 * @copyright 2009-2019 Vanilla Forums Inc.
 * @license GPL-2.0-only
 */

import { IDiscussion } from "@dashboard/@types/api/discussion";
import { IUserFragment } from "@library/@types/api/users";

export interface IComment {
    commentID: number;
    discussionID: IDiscussion["discussionID"];
    body: string;
    dateInserted: string;
    dateUpdated: string | null;
    insertUserID: number;
    score: null;
    insertUser: IUserFragment;
    url: string;
    attributes: any;
}

export interface ICommentEdit {
    commentID: number;
    discussionID: IDiscussion["discussionID"];
    body: string;
    format: string;
}

export interface ICommentEmbed {
    commentID: number;
    type: "quote";
    dateInserted: string;
    dateUpdated: string | null;
    insertUser: IUserFragment;
    url: string;
    format: string;
    body?: string;
    bodyRaw: string;
}
