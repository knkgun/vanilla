/**
 * @author Dominic Lacaille <dominic.lacaille@vanillaforums.com>
 * @copyright 2009-2021 Vanilla Forums Inc.
 * @license GPL-2.0-only
 */

import { css } from "@emotion/css";
import { InputSize } from "../../types";

export interface AutoCompleteClassesProps {
    size?: InputSize;
}

export const autoCompleteClasses = ({ size = "default" }: AutoCompleteClassesProps) => ({
    arrowButton: css({}),
    reachCombobox: css({
        width: "100%",
    }),
    inputContainer: css({
        position: "relative",
    }),
    inputActions: css({
        display: "flex",
        pointerEvents: "none",
        flexDirection: "row-reverse",
        alignItems: "stretch",
        position: "absolute",
        top: 0,
        right: 0,
        bottom: 0,

        ...{
            small: { padding: "0 8px" },
            default: { padding: "0 12px" },
        }[size],
    }),
    inputTokens: css({
        display: "flex",
        pointerEvents: "none",
        flexDirection: "row",
        position: "absolute",
        top: 0,
        left: 0,
        bottom: 0,
        flexWrap: "wrap",
        ...{
            small: {
                height: 28,
            },
            default: {
                height: 36,
            },
        }[size],
    }),
    inputTokenTag: css({
        display: "flex",
        ...{
            small: { margin: 4, maxHeight: 20, paddingLeft: 6, fontSize: "13px" },
            default: { margin: 5, maxHeight: 26, paddingLeft: 8, fontSize: "16px" },
        }[size],
        backgroundColor: "#eeefef",
        borderRadius: 2,
        alignItems: "center",
    }),
    popover: css({
        "&[data-reach-combobox-popover]": {
            zIndex: 9999,
            background: "#fff",
            border: 0,
            borderRadius: 6,
            boxShadow: "0 5px 10px 0 rgba(0, 0, 0, 0.3)",
            maxHeight: "300px",
            overflow: "auto",
        },
        "&[data-autocomplete-state=selected] [data-user-value]": {
            fontWeight: "inherit",
        },
    }),
    checkmarkContainer: css({
        display: "flex",
        height: "1em",
    }),
    option: css({
        display: "flex",
        alignItems: "center",
        "&[data-reach-combobox-option]": {
            ...{
                small: { padding: "6px 8px", fontSize: "13px" },
                default: { padding: "8px 12px", fontSize: "16px" },
            }[size],

            "&:hover, &[data-highlighted]": {
                background: "rgba(3,125,188,0.08)",
            },
        },
        "[data-suggested-value]": {
            fontWeight: "inherit",
        },
        "[data-user-value]": {
            fontWeight: 600,
        },
        svg: {
            position: "relative",

            ...{
                small: { width: 18, height: 18, right: -3, transform: "translateY(calc(0.5em - 50%))" },
                default: { width: 24, height: 24, right: -4, transform: "translateY(calc(0.5em - 50%))" },
            }[size],
        },
    }),
    optionText: css({
        flex: 1,
    }),
    autoCompleteArrow: css({
        display: "flex",
    }),
    autoCompleteClear: css({
        display: "flex",
        pointerEvents: "auto",
        cursor: "pointer",
    }),
    autoCompleteClose: css({
        ...{
            small: { height: 8, with: 8 },
            default: { height: 10, width: 10 },
        }[size],
        alignSelf: "center",
    }),
    input: css({
        cursor: "default",
    }),
});
