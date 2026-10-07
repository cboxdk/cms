import { o as slugOf } from "./slug-OJHt0rsu.js";
import { usePanelHost } from "@cboxdk/cms-panel/extend";
import { TextInput } from "@cboxdk/cms-panel/experimental";
import { jsx, jsxs } from "react/jsx-runtime";
//#region workbench/addons/fixtureaddon/resources/panel/src/SlugInput.tsx
/** What is typed, shaped as it is typed: a hyphen at the end stays, so the next word can follow. */
function shapeTyped(text) {
	return text.toLowerCase().replaceAll(/[^a-z0-9]+/g, "-").replaceAll(/^-+/g, "").slice(0, 120);
}
function SlugInput(props) {
	const { t } = usePanelHost();
	const value = props.value ?? "";
	const slug = slugOf(value);
	return /* @__PURE__ */ jsxs("div", {
		"data-fixtureaddon-slug-input": props.path,
		children: [/* @__PURE__ */ jsx(TextInput, {
			id: props.id,
			name: props.path,
			label: props.label,
			description: t("fixtureaddon.slug_input.description"),
			error: props.errors[0],
			required: props.presence === "required",
			disabled: props.read_only,
			value,
			autoComplete: "off",
			spellCheck: false,
			onChange: (event) => {
				const shaped = shapeTyped(event.target.value);
				props.onChange(shaped === "" ? null : shaped);
			},
			onBlur: () => {
				if (slug !== value) props.onChange(slug);
			}
		}), /* @__PURE__ */ jsx("p", {
			"data-fixtureaddon-slug-preview": slug ?? "",
			children: slug === null ? t("fixtureaddon.slug_input.empty") : t("fixtureaddon.slug_input.preview", { slug })
		})]
	});
}
//#endregion
export { SlugInput as default, shapeTyped };
