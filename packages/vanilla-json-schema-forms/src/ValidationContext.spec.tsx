/**
 * @author Maneesh Chiba <maneesh.chiba@vanillaforums.com>
 * @copyright 2009-2022 Vanilla Forums Inc.
 * @license Proprietary
 */

import React from "react";
import { fireEvent, render, waitFor } from "@testing-library/react";
import { useFormValidation, ValidationProvider } from "./ValidationContext";
import { renderHook } from "@testing-library/react-hooks";
import { act } from "react-dom/test-utils";
import { JsonSchema } from "./types";

const MOCK_SCHEMA: JsonSchema = {
    type: "object",
    properties: {
        mockProperty: {
            type: "string",
            minLength: 1,
            "x-control": {
                label: "Mock Title",
                inputType: "textBox",
            },
        },
    },
};

describe("ValidationProvider", () => {
    it("validate function returns no errors", () => {
        const wrapper = ({ children }) => <ValidationProvider>{children}</ValidationProvider>;
        const { result } = renderHook(() => useFormValidation(), { wrapper });

        act(() => {
            const validation = result.current.validate(MOCK_SCHEMA, { mockProperty: "Example value" });
            expect(validation.isValid).toBeTruthy();
            expect(validation.errors?.length).toBe(0);
        });
    });
    it("validate function returns generic error messages, when none are specified", () => {
        const wrapper = ({ children }) => <ValidationProvider>{children}</ValidationProvider>;
        const { result } = renderHook(() => useFormValidation(), { wrapper });

        act(() => {
            const validation = result.current.validate(MOCK_SCHEMA, { mockProperty: "" });
            expect(validation.isValid).toBeFalsy();
            expect(validation.errors?.length).not.toBe(0);
            expect(validation.errors[0].message).toBe("must NOT have fewer than 1 characters");
        });
    });
    it("validate function returns custom error messages, when specified", () => {
        const wrapper = ({ children }) => <ValidationProvider>{children}</ValidationProvider>;
        const { result } = renderHook(() => useFormValidation(), { wrapper });

        act(() => {
            const MOCK_SCHEMA_WITH_CUSTOM_MESSAGE = {
                ...MOCK_SCHEMA,
                properties: {
                    ...MOCK_SCHEMA.properties,
                    mockProperty: {
                        ...MOCK_SCHEMA.properties.mockProperty,
                        errorMessage: "Custom error message",
                    },
                },
            };
            const validation = result.current.validate(MOCK_SCHEMA_WITH_CUSTOM_MESSAGE, { mockProperty: "" });
            expect(validation.isValid).toBeFalsy();
            expect(validation.errors?.length).not.toBe(0);
            expect(validation.errors[0].message).toBe("Custom error message");
        });
    });
});
