import __ from 'orotranslation/js/translator';
import AiContentDialogWidget from 'oroaicontentgeneration/js/app/views/ai-content-dialog-widget-view';
import BaseType from 'orocms/js/app/grapesjs/types/base-type';

const AIGenerationType = BaseType.extend({
    button: {
        label: __('oro.ai_content_generation.wysiwyg.component.aigeneration.title'),
        tooltip: __('oro.ai_content_generation.wysiwyg.component.aigeneration.tooltip'),
        category: 'Basic',
        attributes: {
            'class': 'fa fa-commenting'
        },
        activate: true
    },

    modelProps: {
        defaults: {
            classes: ['component-class', 'ai-generation'],
            tagName: 'div',
            editable: true
        },

        addTextContent(editor, content) {
            const selected = editor.getSelected();

            const res = selected.replaceWith(
                /^(\s+)?\<[\s\S]+\>/gi.test(content) ? content : `<div>${content}</div>`
            );
            if (res[0]) {
                editor.select(res[0]);
            }
        }
    },

    viewProps: {
        onActive(event) {
            this.em.get('Commands').run('ai-generation-modal-initialize', this.model);

            event && event.stopPropagation();
        }
    },

    commands: {
        'ai-generation-modal-initialize': (editor, sender, componentModel) => {
            const widget = new AiContentDialogWidget({
                fieldName: editor.parentView.$el.attr('name'),
                openPromptTasks: editor.em.get('openPromptTasks'),
                onConfirm: function(content) {
                    componentModel.addTextContent(editor, content);
                }
            });

            widget.render();
        }
    },

    usedTags: ['div'],

    constructor: function AIGenerationType(options) {
        AIGenerationType.__super__.constructor.call(this, options);
    },

    isComponent(el) {
        return el.nodeType === Node.ELEMENT_NODE && el.tagName === 'DIV' && el.classList.contains('ai-generation');
    }
}, {
    // Define static property with type name
    type: 'ai-generation'
});

export default AIGenerationType;
