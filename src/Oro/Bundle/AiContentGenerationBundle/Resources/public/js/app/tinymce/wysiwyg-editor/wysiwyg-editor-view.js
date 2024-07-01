import WysiwygEditorView from 'oroform/js/app/views/wysiwig-editor/wysiwyg-editor-view';
import AiContentDialogWidget from 'oroaicontentgeneration/js/app/views/ai-content-dialog-widget-view';
import tinyMCE from 'tinymce/tinymce';
import __ from 'orotranslation/js/translator';
import $ from 'jquery';

const DecoratedWysiwygEditorView = WysiwygEditorView.extend({
    formOptions: {
        openPromptTasks: []
    },

    constructor: function DecoratedWysiwygEditorView(options) {
        DecoratedWysiwygEditorView.__super__.constructor.call(this, options);
    },

    initialize(options) {
        this.formOptions.openPromptTasks = options.openPromptTasks;
        DecoratedWysiwygEditorView.__super__.initialize.call(this, options);
    },

    connectTinyMCE: function() {
        this.options.plugins.push('ai-content-generation');
        this.options.toolbar += ' | ai-content-generation';

        tinyMCE.PluginManager.add('ai-content-generation', editor => {
            editor.ui.registry.addButton('ai-content-generation', {
                tooltip: __('oro.ai_content_generation.wysiwyg.component.aigeneration.tooltip'),
                icon: 'comment',
                onAction: () => {
                    const widget = new AiContentDialogWidget({
                        fieldName: $(`#${editor.id}`).attr('name'),
                        openPromptTasks: this.formOptions.openPromptTasks,
                        onConfirm: function(content) {
                            editor.insertContent(content);
                        }
                    });

                    widget.render();
                }
            });
        });

        DecoratedWysiwygEditorView.__super__.connectTinyMCE.call(this);
    }
});

export default DecoratedWysiwygEditorView;
