// The lint rule against className and style props on a component of the kit (GUARDRAILS 8: one
// component kit). A kit component's appearance comes from its variant, tone, size and density
// props and from the design tokens; its markup and class names are internal. So the props of an
// exported component, a function whose name starts with a capital letter and that is exported
// directly or through memo or forwardRef, may not have a className or a style property. The rule
// reads the props' type, so a props interface that extends HTMLAttributes without leaving the two
// out is reported as well as one that names them.

import { AST_NODE_TYPES, ESLintUtils } from '@typescript-eslint/utils';

/** @typedef {import('@typescript-eslint/utils').TSESTree.Node} Node */
/** @typedef {import('@typescript-eslint/utils').TSESTree.Parameter} Parameter */

/** The props a kit component may not accept. */
export const STYLE_PROPS = ['className', 'style'];

const COMPONENT = /^[A-Z]/;

const createRule = ESLintUtils.RuleCreator(
  () =>
    'https://github.com/cboxdk/cms/blob/main/docs/developers/gates-and-ci.md#the-panel-and-the-component-kit',
);

/**
 * The function of a component's definition: the function itself, or the function given to a
 * wrapper such as memo(...) or forwardRef(...).
 *
 * @param {Node | null | undefined} node
 * @returns {Node | null}
 */
function componentFunction(node) {
  if (node === null || node === undefined) {
    return null;
  }

  switch (node.type) {
    case AST_NODE_TYPES.FunctionDeclaration:
    case AST_NODE_TYPES.FunctionExpression:
    case AST_NODE_TYPES.ArrowFunctionExpression:
      return node;
    case AST_NODE_TYPES.CallExpression:
      return componentFunction(node.arguments[0]);
    case AST_NODE_TYPES.TSAsExpression:
    case AST_NODE_TYPES.TSSatisfiesExpression:
      return componentFunction(node.expression);
    default:
      return null;
  }
}

/**
 * The first parameter of a function node, or null.
 *
 * @param {Node} node
 * @returns {Parameter | null}
 */
function propsParameter(node) {
  if (
    node.type === AST_NODE_TYPES.FunctionDeclaration ||
    node.type === AST_NODE_TYPES.FunctionExpression ||
    node.type === AST_NODE_TYPES.ArrowFunctionExpression
  ) {
    return node.params[0] ?? null;
  }

  return null;
}

export const noStyleProps = createRule({
  name: 'no-style-props',
  meta: {
    type: 'problem',
    docs: {
      description:
        'Reject className and style props on a component of the kit; appearance comes from variant props and tokens.',
    },
    schema: [],
    messages: {
      prop: 'The kit component "{{component}}" accepts the prop "{{prop}}". A kit component takes no className or style: leave it out of the props (Omit<..., \'className\' | \'style\'>) and offer a variant, tone, size or density prop instead.',
    },
  },
  defaultOptions: [],
  create(context) {
    const services = ESLintUtils.getParserServices(context);
    const checker = services.program.getTypeChecker();

    /**
     * @param {string} name
     * @param {Node | null | undefined} definition
     * @param {Node} reportAt
     */
    function check(name, definition, reportAt) {
      if (!COMPONENT.test(name)) {
        return;
      }

      const component = componentFunction(definition);
      const parameter = component === null ? null : propsParameter(component);

      if (parameter === null) {
        return;
      }

      const type = checker.getApparentType(services.getTypeAtLocation(parameter));
      const types = type.isUnion() ? type.types : [type];

      for (const prop of STYLE_PROPS) {
        if (types.some((member) => checker.getPropertyOfType(member, prop) !== undefined)) {
          context.report({ node: reportAt, messageId: 'prop', data: { component: name, prop } });
        }
      }
    }

    return {
      ExportNamedDeclaration(node) {
        const declaration = node.declaration;

        if (declaration?.type === AST_NODE_TYPES.FunctionDeclaration && declaration.id !== null) {
          check(declaration.id.name, declaration, declaration.id);
        }

        if (declaration?.type === AST_NODE_TYPES.VariableDeclaration) {
          for (const declarator of declaration.declarations) {
            if (declarator.id.type === AST_NODE_TYPES.Identifier) {
              check(declarator.id.name, declarator.init, declarator.id);
            }
          }
        }

        if (node.source !== null) {
          return;
        }

        // export { Component } of a function or constant declared earlier in the module.
        const scope = context.sourceCode.getScope(node);

        for (const specifier of node.specifiers) {
          const exported =
            specifier.exported.type === AST_NODE_TYPES.Identifier
              ? specifier.exported.name
              : specifier.exported.value;
          const variable = scope.set.get(specifier.local.name);

          for (const definition of variable?.defs ?? []) {
            if (definition.node.type === AST_NODE_TYPES.FunctionDeclaration) {
              check(exported, definition.node, specifier);
            } else if (definition.node.type === AST_NODE_TYPES.VariableDeclarator) {
              check(exported, definition.node.init, specifier);
            }
          }
        }
      },
      ExportDefaultDeclaration(node) {
        const declaration = node.declaration;

        if (declaration.type === AST_NODE_TYPES.FunctionDeclaration) {
          check(declaration.id?.name ?? 'Default', declaration, declaration);
        }
      },
    };
  },
});
