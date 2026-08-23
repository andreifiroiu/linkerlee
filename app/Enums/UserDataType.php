<?php

namespace App\Enums;

/**
 * A category of account data that can be wiped without closing the account.
 *
 * The values mirror the import and export vocabulary, so the same three words
 * mean the same three things whichever direction the data is moving. Shares
 * have no import counterpart but are worth revoking on their own: they are the
 * only part of an account that is readable without logging in.
 *
 * The declaration order is also the order the deletion runs in, regardless of
 * the order the caller asked for. Links have to go first, because deleting a
 * link is what releases its tags and its share.
 */
enum UserDataType: string
{
    case Links = 'links';
    case Groups = 'groups';
    case Tags = 'tags';
    case Shares = 'shares';
}
