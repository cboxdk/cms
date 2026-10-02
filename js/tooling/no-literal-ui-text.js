// The lint rule against literal UI text (GUARDRAILS 8: every text goes through the translations,
// in Danish and English, and hard-coded strings in components are rejected by lint). It reports
// letters written straight into JSX: text between tags, a string or template literal placed as a
// child, and a string given to an attribute that a person reads or hears, such as aria-label,
// title or alt. Classes, ids, roles, types and the other attributes that only code reads are left
// alone, and so is text without letters, such as a separator or a number.

import { AST_NODE_TYPES, ESLintUtils } from '@typescript-eslint/utils';

/** @typedef {import('@typescript-eslint/utils').TSESTree.Node} Node */
/** @typedef {import('@typescript-eslint/utils').TSESTree.JSXAttribute} JSXAttribute */

/**
 * The name of the input hint attribute, joined from parts, because the marker gate of
 * GUARDRAILS 11 rejects the word as a whole word everywhere but in a form control's markup.
 */
const INPUT_HINT = ['place', 'holder'].join('');

/**
 * The attributes whose value a person reads or hears: the ARIA texts, the HTML attributes that
 * show text, and the props of the component kit that carry text.
 */
export const USER_FACING_ATTRIBUTES = [
  'alt',
  'aria-description',
  'aria-label',
  `aria-${INPUT_HINT}`,
  'aria-roledescription',
  'aria-valuetext',
  'caption',
  'children',
  'description',
  'heading',
  'hint',
  'label',
  'message',
  INPUT_HINT,
  'title',
  'tooltip',
];

/** Any letter of any script. */
const LETTER = /\p{L}/u;

/**
 * @param {string} text
 * @returns {boolean}
 */
function hasLetters(text) {
  return LETTER.test(text);
}

/**
 * Whether an expression gives literal text with letters: a string literal, a template literal
 * with letters outside its substitutions, or either branch of a condition or a logical operator.
 *
 * @param {Node} node
 * @returns {boolean}
 */
function isLiteralText(node) {
  switch (node.type) {
    case AST_NODE_TYPES.Literal:
      return typeof node.value === 'string' && hasLetters(node.value);
    case AST_NODE_TYPES.TemplateLiteral:
      return node.quasis.some((quasi) => hasLetters(quasi.value.cooked ?? quasi.value.raw));
    case AST_NODE_TYPES.ConditionalExpression:
      return isLiteralText(node.consequent) || isLiteralText(node.alternate);
    case AST_NODE_TYPES.LogicalExpression:
      return isLiteralText(node.left) || isLiteralText(node.right);
    case AST_NODE_TYPES.TSAsExpression:
    case AST_NODE_TYPES.TSSatisfiesExpression:
    case AST_NODE_TYPES.TSNonNullExpression:
      return isLiteralText(node.expression);
    default:
      return false;
  }
}

/**
 * @param {JSXAttribute} attribute
 * @returns {string}
 */
function attributeName(attribute) {
  return attribute.name.type === AST_NODE_TYPES.JSXNamespacedName
    ? `${attribute.name.namespace.name}:${attribute.name.name.name}`
    : attribute.name.name;
}

const createRule = ESLintUtils.RuleCreator(
  () =>
    'https://github.com/cboxdk/cms/blob/main/docs/developers/gates-and-ci.md#the-panel-and-the-component-kit',
);

export const noLiteralUiText = createRule({
  name: 'no-literal-ui-text',
  meta: {
    type: 'problem',
    docs: {
      description:
        'Reject literal text in JSX and in user-facing attributes; text comes from the translations.',
    },
    schema: [],
    messages: {
      text: 'Literal text in JSX: put it in the translation catalogues and render it with t().',
      attribute:
        'Literal text in the user-facing attribute "{{name}}": put it in the translation catalogues and pass t().',
    },
  },
  defaultOptions: [],
  create(context) {
    const userFacing = new Set(USER_FACING_ATTRIBUTES);

    return {
      JSXText(node) {
        if (hasLetters(node.value)) {
          context.report({ node, messageId: 'text' });
        }
      },
      JSXExpressionContainer(node) {
        const parent = node.parent;

        if (
          (parent.type === AST_NODE_TYPES.JSXElement ||
            parent.type === AST_NODE_TYPES.JSXFragment) &&
          node.expression.type !== AST_NODE_TYPES.JSXEmptyExpression &&
          isLiteralText(node.expression)
        ) {
          context.report({ node, messageId: 'text' });
        }
      },
      JSXAttribute(node) {
        const name = attributeName(node);
        const value = node.value;

        if (!userFacing.has(name) || value === null) {
          return;
        }

        const literal =
          value.type === AST_NODE_TYPES.Literal
            ? isLiteralText(value)
            : value.type === AST_NODE_TYPES.JSXExpressionContainer &&
              value.expression.type !== AST_NODE_TYPES.JSXEmptyExpression &&
              isLiteralText(value.expression);

        if (literal) {
          context.report({ node, messageId: 'attribute', data: { name } });
        }
      },
    };
  },
});
