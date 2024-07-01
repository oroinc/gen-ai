import GrapesJS from 'grapesjs';
import ComponentManager from 'orocms/js/app/grapesjs/plugins/components/component-manager';
import AIGenerationType from 'oroaicontentgeneration/js/app/grapesjs/types/ai-generation-type';

export default GrapesJS.plugins.add('aigeneration-plugin', (editor, options) => {
    editor.em.set('openPromptTasks', options.openPromptTasks);
    ComponentManager.registerComponentType('aigeneration', {
        Constructor: AIGenerationType
    });
});
