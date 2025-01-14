/**
 * @author Stéphane LaFlèche <stephane.l@vanillaforums.com>
 * @copyright 2009-2019 Vanilla Forums Inc.
 * @license GPL-2.0-only
 */

import React, { RefObject } from "react";
import classNames from "classnames";
import { t } from "@library/utility/appUtils";
import { LiveMessage } from "react-aria-live";
import { messagesClasses } from "@library/messages/messageStyles";
import Button from "@library/forms/Button";
import Container from "@library/layout/components/Container";
import { ButtonTypes } from "@library/forms/buttonTypes";
import ButtonLoader from "@library/loaders/ButtonLoader";
import ConditionalWrap from "@library/layout/ConditionalWrap";
import { cx } from "@emotion/css";

export interface IMessageProps {
    className?: string;
    contents?: React.ReactNode;
    stringContents: string;
    clearOnUnmount?: boolean; // reannounces the message if the page gets rerendered. This is an important message, so we want this by default.
    onConfirm?: () => void;
    confirmText?: React.ReactNode;
    onCancel?: () => void;
    cancelText?: React.ReactNode;
    isFixed?: boolean;
    isContained?: boolean;
    title?: React.ReactNode;
    isActionLoading?: boolean;
    icon?: React.ReactNode | false;
}

export const Message = React.forwardRef(function Message(props: IMessageProps, ref: RefObject<HTMLDivElement>) {
    const classes = messagesClasses();

    // When fixed we need to apply an extra layer for padding.
    const InnerWrapper = props.isContained ? Container : React.Fragment;
    const OuterWrapper = props.isFixed ? Container : React.Fragment;
    const contents = <p className={classes.content}>{props.contents || props.stringContents}</p>;

    const hasTitle = !!props.title;
    const hasIcon = !!props.icon;

    const content = <div className={classes.text}>{contents}</div>;
    const title = props.title && <h2 className={classes.title}>{props.title}</h2>;

    const icon_content = !hasTitle && hasIcon; //case - if message has icon and content.
    const icon_title_content = hasTitle && hasIcon; //case - if message has icon, title and content.
    const noIcon = !hasIcon; // //case - if message has title, content and no icon

    const iconMarkup = <div className={classes.iconPosition}>{props.icon}</div>;

    return (
        <>
            <div
                ref={ref}
                className={cx(classes.root, props.className, {
                    [classes.fixed]: props.isFixed,
                })}
            >
                <OuterWrapper>
                    <div
                        className={cx(classes.wrap, {
                            [classes.fixed]: props.isContained,
                            [classes.wrapWithIcon]: !!props.icon,
                        })}
                    >
                        <InnerWrapper>
                            <div className={classes.message}>
                                {icon_content && (
                                    <div className={classes.titleContent}>
                                        {iconMarkup} {content}
                                    </div>
                                )}
                                {icon_title_content && (
                                    <>
                                        <div className={classes.titleContent}>
                                            {iconMarkup} {title}
                                        </div>
                                        {content}
                                    </>
                                )}
                                {noIcon && (
                                    <>
                                        {title}
                                        {content}
                                    </>
                                )}
                            </div>
                            {props.onCancel && (
                                <Button
                                    buttonType={ButtonTypes.TEXT}
                                    onClick={props.onCancel}
                                    className={classes.actionButton}
                                    disabled={!!props.isActionLoading}
                                >
                                    {props.isActionLoading ? <ButtonLoader /> : props.cancelText || t("Cancel")}
                                </Button>
                            )}
                            {props.onConfirm && (
                                <Button
                                    buttonType={ButtonTypes.TEXT}
                                    onClick={props.onConfirm}
                                    className={cx(classes.actionButton, classes.actionButtonPrimary)}
                                    disabled={!!props.isActionLoading}
                                >
                                    {props.isActionLoading ? <ButtonLoader /> : props.confirmText || t("OK")}
                                </Button>
                            )}
                        </InnerWrapper>
                    </div>
                </OuterWrapper>
            </div>
            {/* Does not visually render, but sends message to screen reader users*/}
            <LiveMessage clearOnUnmount={!!props.clearOnUnmount} message={props.stringContents} aria-live="assertive" />
        </>
    );
});

export default Message;
