import DialogWidget from 'oro/dialog-widget';
import tools from 'oroui/js/tools';
import __ from 'orotranslation/js/translator';
import _ from 'underscore';
import routing from 'routing';
import $ from 'jquery';

const MOBILE_WIDTH = 375;

const AiContentDialogWidgetView = DialogWidget.extend({
    options: _.extend({}, DialogWidget.prototype.options, {
        title: __('oro.ai_content_generation.wysiwyg.component.aigeneration.title'),
        dialogOptions: {
            modal: true,
            resizable: true,
            autoResize: true,
            allowMaximize: true,
            minWidth: tools.isMobile() ? MOBILE_WIDTH : 800
        }
    }),

    formOptions: {
        renderingRoute: 'oro_ai_content_generation_form',

        previewSelectorFtid: 'oro_ai_content_generation_preview',
        formRenderingRoute: 'oro_ai_content_generation_form',
        confirmBtnSelector: '.js-ai-generation-confirm',

        taskSelectorFtid: 'oro_ai_content_generation_task',
        contentSelectorFtid: 'oro_ai_content_generation_content',
        sourceFormSubmittedFormFieldSelectorFtid: 'oro_ai_content_generation_source_form_submitted_form_field'
    },

    /**
     * @property {Function}
     */
    onConfirm: null,

    fieldName: null,

    openPromptTasks: [],

    previouslySelectedTask: null,

    listen: {
        renderComplete: 'onRenderComplete'
    },

    events() {
        return {
            [`change [data-ftid="${this.formOptions.taskSelectorFtid}"]`]: 'onChangeTaskField'
        };
    },

    constructor: function AiContentDialogWidgetView(options) {
        AiContentDialogWidgetView.__super__.constructor.call(this, options);
    },

    initialize(options) {
        this.onConfirm = options.onConfirm;
        this.fieldName = options.fieldName;
        this.openPromptTasks = options.openPromptTasks;

        AiContentDialogWidgetView.__super__.initialize.call(this, {
            ...options,
            url: routing.generate(this.formOptions.renderingRoute),
            method: 'post'
        });
    },

    prepareContentRequestOptions: function(data, method, url) {
        if (url === routing.generate(this.formOptions.renderingRoute)) {
            data = $.param(this.prepareRenderingFormData(this.fieldName));
        }

        return AiContentDialogWidgetView.__super__.prepareContentRequestOptions.call(
            this,
            data,
            method,
            url
        );
    },

    onRenderComplete() {
        this.actionsEl.on(`click${this.eventNamespace()}`, this.formOptions.confirmBtnSelector, () => {
            const $contentField = this.$el.find(`[data-ftid="${this.formOptions.previewSelectorFtid}"]`);

            if ($contentField.length > 0) {
                this.onConfirm($contentField.val());

                this.remove();
            }
        });

        this.$el.find(`[data-ftid="${this.formOptions.taskSelectorFtid}"]`).trigger('change');
    },

    onChangeTaskField(event) {
        const contentEl = this.$el.find(`[data-ftid=${this.formOptions.contentSelectorFtid}]`);
        const contentElContainer = contentEl.closest('.control-group');
        const isRelated = this.openPromptTasks.includes($(event.target).val());

        contentEl.attr({disabled: !isRelated});
        contentElContainer.attr({hidden: !isRelated});

        if (!isRelated) {
            return;
        }

        const url = routing.generate('oro_ai_content_generation_form_content');
        const renderingFormData = this.prepareRenderingFormData(
            this.$el.find(`[data-ftid=${this.formOptions.contentSelectorFtid}]`).attr('name')
        );

        renderingFormData.task = this.$el.find(`[data-ftid=${this.formOptions.taskSelectorFtid}]`).val();

        if (this.previouslySelectedTask === renderingFormData.task) {
            return;
        }

        $.post(url, $.param((renderingFormData))).then(data => contentEl.val(data.content));

        this.previouslySelectedTask = renderingFormData.task;
    },

    prepareRenderingFormData(fieldName) {
        const $pageForm = this.identifyOroFormOnPage(fieldName);

        const formData = {
            submitted_form_name: $pageForm.attr('name'),
            submitted_form_field: fieldName,
            submitted_form_data: {}
        };

        formData.submitted_form_data = $pageForm
            .serializeArray()
            .reduce((obj, field) => {
                if (field.name.includes($pageForm.attr('name')) ) {
                    const name = field.name.replace(formData.submitted_form_name, '').slice(1, -1);
                    obj[name] = field.value;
                }
                return obj;
            }, formData.submitted_form_data);

        return formData;
    },

    identifyOroFormOnPage(fieldName) {
        let $form;

        $form = $('#container form').filter(function() {
            const formName = $(this).attr('name');

            if (!formName) {
                return false;
            }

            return formName.includes('oro_');
        }).first();

        if (!$form.length) {
            $form = $(`[name='${fieldName}']`).closest('form');

            if ($form.length === 0 || !$form.attr('name').includes('oro_')) {
                throw new Error('There is no form on the page that can be processed');
            }
        }

        return $form;
    },

    dispose: function() {
        if (this.disposed) {
            return;
        }

        this.actionsEl.off(this.eventNamespace());

        AiContentDialogWidgetView.__super__.dispose.call(this);
    }
});

export default AiContentDialogWidgetView;
