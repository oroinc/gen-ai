<?php

namespace Oro\Bundle\AiContentGenerationBundle\Form\Type;

use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationClientInterface;
use Oro\Bundle\AiContentGenerationBundle\Exception\ContentGenerationClientException;
use Oro\Bundle\AiContentGenerationBundle\Factory\ContentGenerationRequestFactory;
use Oro\Bundle\AiContentGenerationBundle\Provider\TasksProvider;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Task\TaskInterface;
use Oro\Bundle\FormBundle\Form\DataTransformer\ArrayToJsonTransformer;
use Oro\Bundle\FormBundle\Form\Type\Select2ChoiceType;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Represents Content AI generation form
 */
class AiContentGenerationFormType extends AbstractType implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const  BLOCK_PREFIX = 'oro_ai_content_generation';

    public function __construct(
        private readonly ContentGenerationClientInterface $contentGenerationClient,
        private readonly TasksProvider                    $tasksProvider,
        private readonly ContentGenerationRequestFactory  $contentGenerationRequestFactory,
        private readonly TranslatorInterface              $translator,
        private iterable                                  $tones,
    ) {
        $this->logger = new NullLogger();
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, [$this, 'preSetData']);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, [$this, 'preSubmit']);

        $builder
            ->add(
                'tone',
                Select2ChoiceType::class,
                [
                    'label' => $this->getFieldLabel('tone'),
                    'required' => true,
                    'choices' => $this->getTonesChoices()
                ]
            );

        $builder
            ->add('source_form_submitted_form_name', HiddenType::class)
            ->add('source_form_submitted_form_data', HiddenType::class)
            ->add('source_form_submitted_form_field', HiddenType::class);

        $builder->get('source_form_submitted_form_data')->addViewTransformer(new ArrayToJsonTransformer());
    }

    public function preSetData(FormEvent $event): void
    {
        $data = $event->getData();

        try {
            if ($this->contentGenerationClient->supportsUserContentSize()) {
                $event->getForm()
                    ->add(
                        'content_size',
                        Select2ChoiceType::class,
                        [
                            'label' => $this->getFieldLabel('content_size'),
                            'required' => true,
                            'choices' => [
                                $this->getFieldLabel('content_size.choices.small') => 'small',
                                $this->getFieldLabel('content_size.choices.medium') => 'medium',
                                $this->getFieldLabel('content_size.choices.large') => 'large',
                            ],
                            'data' => 'medium'
                        ]
                    );
            }
        } catch (ContentGenerationClientException $clientException) {
            $this->addFormError($event->getForm(), $clientException);
            return;
        }

        if (is_string($data['source_form_submitted_form_data'])) {
            $data['source_form_submitted_form_data'] = json_decode(
                $data['source_form_submitted_form_data'],
                true
            );
        }

        $request = UserContentGenerationRequest::fromSubmitRequest($data);
        $tasks = $this->tasksProvider->getTasks($request);
        $taskChoices = $this->getTaskChoices($tasks);
        $tasksSupportedUserContentKeys = $this->tasksProvider->getOpenPromptTaskKeys();

        $event->getForm()
            ->add(
                'task',
                Select2ChoiceType::class,
                [
                    'label' => $this->getFieldLabel('task'),
                    'required' => true,
                    'choices' => $taskChoices,
                ]
            );

        if (!isset($data['task'])) {
            $data['task'] = current($taskChoices);
        }

        if (in_array($data['task'], $tasksSupportedUserContentKeys)) {
            $data['content'] = $this->tasksProvider->getTaskFormPredefinedContent($data['task'], $request);
        }

        $event->getForm()
            ->add(
                'content',
                TextareaType::class,
                [
                    'constraints' => $this->getContentConstraints($data['task'], $tasksSupportedUserContentKeys),
                    'label' => $this->getFieldLabel('content'),
                    'required' => true,
                    'tooltip' => 'oro_ai_content_generation.form.field.content.tooltip'
                ]
            );

        $event->setData($data);
    }

    public function preSubmit(FormEvent $formEvent): void
    {
        $formEvent->getForm()->add(
            'preview',
            TextareaType::class,
            [
                'label' => 'oro_ai_content_generation.form.field.preview.label',
                'required' => false,
                'tooltip' => 'oro_ai_content_generation.form.field.preview.tooltip',
            ]
        );
    }

    public function getBlockPrefix(): string
    {
        return self::BLOCK_PREFIX;
    }

    private function getContentConstraints(string $task, array $tasksSupportedUserContentKeys): array
    {
        return in_array($task, $tasksSupportedUserContentKeys) ? [new NotBlank()] : [];
    }

    private function getTonesChoices(): array
    {
        $choices = [];

        foreach ($this->tones as $tone) {
            $choices[$this->getFieldLabel('tone.choices.' . $tone)] = $tone;
        }

        return $choices;
    }

    /**
     * @param iterable<int, TaskInterface> $tasks
     */
    private function getTaskChoices(iterable $tasks): array
    {
        $choices = [];

        foreach ($tasks as $task) {
            $choices[$this->getFieldLabel('task.choices.' . $task->getKey())] = $task->getKey();
        }

        return $choices;
    }

    private function getFieldLabel(string $fieldName): string
    {
        return sprintf(
            'oro_ai_content_generation.form.field.%s.%s',
            $fieldName,
            'label'
        );
    }

    private function addFormError(
        FormInterface $form,
        ContentGenerationClientException $exception
    ): void {
        $this->logger->error(
            'AI Content Generation Client error occurred',
            ['exception' => $exception]
        );

        $form->addError(new FormError($this->translator->trans('oro_ai_content_generation.form.error.label')));
    }
}
