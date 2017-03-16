<?php

namespace Oro\Bundle\WorkflowBundle\Model;

use Symfony\Component\Form\FormRegistry;
use Symfony\Component\Form\Guess\TypeGuess;
use Symfony\Component\PropertyAccess\PropertyPath;
use Symfony\Component\PropertyAccess\PropertyPathInterface;
use Symfony\Component\Validator\Constraint;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;

use Oro\Bundle\ActionBundle\Exception\AttributeException;
use Oro\Bundle\ActionBundle\Provider\DoctrineTypeMappingProvider;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;

class VariableGuesser
{
    const DEFAULT_CONSTRAINT_NAMESPACE = 'Symfony\\Component\\Validator\\Constraints\\';

    /**
     * @var FormRegistry
     */
    protected $formRegistry;

    /**
     * @var ManagerRegistry
     */
    protected $managerRegistry;

    /**
     * @var ConfigProvider
     */
    protected $entityConfigProvider;

    /**
     * @var ConfigProvider
     */
    protected $formConfigProvider;

    /**
     * @var array
     */
    protected $doctrineTypeMapping = [];

    /**
     * @var array
     */
    protected $formTypeMapping = [];

    /**
     * @var DoctrineTypeMappingProvider|null
     */
    protected $doctrineTypeMappingProvider;

    /**
     * @param FormRegistry $formRegistry
     * @param ManagerRegistry $managerRegistry
     * @param ConfigProvider $entityConfigProvider
     * @param ConfigProvider $formConfigProvider
     */
    public function __construct(
        FormRegistry $formRegistry,
        ManagerRegistry $managerRegistry,
        ConfigProvider $entityConfigProvider,
        ConfigProvider $formConfigProvider
    ) {
        $this->formRegistry = $formRegistry;
        $this->managerRegistry = $managerRegistry;
        $this->entityConfigProvider = $entityConfigProvider;
        $this->formConfigProvider = $formConfigProvider;
    }

    /**
     * @param DoctrineTypeMappingProvider|null $doctrineTypeMappingProvider
     */
    public function setDoctrineTypeMappingProvider(DoctrineTypeMappingProvider $doctrineTypeMappingProvider = null)
    {
        $this->doctrineTypeMappingProvider = $doctrineTypeMappingProvider;
    }

    /**
     * @param string $doctrineType
     * @param string $attributeType
     * @param array $attributeOptions
     */
    public function addDoctrineTypeMapping($doctrineType, $attributeType, array $attributeOptions = [])
    {
        $this->doctrineTypeMapping[$doctrineType] = [
            'type' => $attributeType,
            'options' => $attributeOptions
        ];
    }

    /**
     * @param string $variableType
     * @param string $formType
     * @param array $formOptions
     */
    public function addFormTypeMapping($variableType, $formType, array $formOptions = [])
    {
        $this->formTypeMapping[$variableType] = [
            'type' => $formType,
            'options' => $formOptions,
        ];
    }

    /**
     * @param Variable $variable
     *
     * @return null|TypeGuess
     */
    public function guessVariableForm(Variable $variable)
    {
        $type = $variable->getType();
        if ('entity' === $type) {
            list($formType, $formOptions) = $this->getEntityForm($variable);

            return new TypeGuess($formType, $formOptions, TypeGuess::VERY_HIGH_CONFIDENCE);
        }

        if (!isset($this->formTypeMapping[$type])) {
            return null;
        }

        $formType = $this->formTypeMapping[$type]['type'];
        $formOptions = array_merge_recursive($this->formTypeMapping[$type]['options'], $variable->getFormOptions());

        $formOptions = $this->setVariableFormOptions($variable, $formOptions);

        return new TypeGuess($formType, $formOptions, TypeGuess::VERY_HIGH_CONFIDENCE);
    }

    /**
     * @param Variable $variable
     * @param array    $formOptions
     *
     * @return array
     */
    protected function setVariableFormOptions(Variable $variable, $formOptions)
    {
        if (null !== $variable->getLabel()) {
            $formOptions['label'] = $variable->getLabel();
        }
        if (null !== $variable->getValue()) {
            $formOptions['data'] = $variable->getValue();
        }

        if (!isset($formOptions['constraints']) || !is_array($formOptions['constraints'])) {
            return $formOptions;
        }

        foreach ($formOptions['constraints'] as $constraint => $constraintOptions) {
            if ($constraintOptions instanceof Constraint) {
                continue;
            }

            unset($formOptions['constraints'][$constraint]);
            if (false === strpos($constraint, '\\')) {
                $constraint = sprintf('%s%s', self::DEFAULT_CONSTRAINT_NAMESPACE, $constraint);
            }
            $formOptions['constraints'][] = new $constraint($constraintOptions);
        }

        return $formOptions;
    }

    /**
     * @param Variable $variable
     *
     * @return array
     *
     */
    protected function getEntityForm(Variable $variable)
    {
        $entityClass = $variable->getOption('class');
        $formType = null;
        $formOptions = [];
        if ($this->formConfigProvider->hasConfig($entityClass)) {
            $formConfig = $this->formConfigProvider->getConfig($entityClass);
            $formType = $formConfig->get('form_type');
            $formOptions = $formConfig->get('form_options', false, []);
        }
        if (!$formType) {
            $formType = 'entity';
            $formOptions = [
                'class' => $entityClass,
                'multiple' => false,
            ];
        }

        $formOptions = $this->setVariableFormOptions($variable, $formOptions);

        return [$formType, $formOptions];
    }

    /**
     * @param string $rootClass
     * @param string|PropertyPathInterface $propertyPath
     *
     * @return array|null
     */
    public function guessVariableParameters($rootClass, $propertyPath)
    {
        $metadataParameters = $this->guessMetadataAndField($rootClass, $propertyPath);
        if (!$metadataParameters) {
            return null;
        }

        /** @var ClassMetadata $metadata */
        $metadata = $metadataParameters['metadata'];
        $field = $metadataParameters['field'];

        $scalarParameters = $this->guessVariableParametersScalarField($metadata, $field);
        if ($scalarParameters !== false) {
            return $scalarParameters;
        }

        if ($metadata->hasAssociation($field)) {
            $multiple = $metadata->isCollectionValuedAssociation($field);
            $type = $multiple
                ? 'object'
                : 'entity';
            $class = $multiple
                ? 'Doctrine\Common\Collections\ArrayCollection'
                : $metadata->getAssociationTargetClass($field);

            return $this->formatResult(
                $this->getLabel($metadata->getName(), $field, $multiple),
                $type,
                ['class' => $class]
            );
        }

        return null;
    }

    /**
     * @param string $rootClass
     * @param string|PropertyPathInterface $propertyPath
     *
     * @return array|null
     */
    public function guessMetadataAndField($rootClass, $propertyPath)
    {
        if (!$propertyPath instanceof PropertyPathInterface) {
            $propertyPath = new PropertyPath($propertyPath);
        }

        $pathElements = array_values($propertyPath->getElements());
        $elementsCount = count($pathElements);
        if ($elementsCount < 2) {
            return null;
        }

        $metadata = $this->getMetadataForClass($rootClass);

        $field = null;
        for ($i = 1; $i < $elementsCount; $i++) {
            $field = $pathElements[$i];
            $hasAssociation = $metadata->hasAssociation($field)
                || $this->entityConfigProvider->hasConfig($rootClass, $field);
            if ($hasAssociation && $i < $elementsCount - 1) {
                $className = $metadata->hasAssociation($field)
                    ? $metadata->getAssociationTargetClass($field)
                    : $this->entityConfigProvider->getConfig($rootClass, $field)->getId()->getClassName();
                $metadata = $this->getMetadataForClass($className);
            } elseif (!$hasAssociation && !$metadata->hasField($field)) {
                return null;
            }
        }

        return [
            'metadata' => $metadata,
            'field' => $field
        ];
    }

    /**
     * Return "false" if can't find config for field, "null" if field type is unknown for given field
     * or array with config data for given field
     *
     * @param ClassMetadata $metadata
     * @param $field
     * @return array|bool
     */
    protected function guessVariableParametersScalarField(ClassMetadata $metadata, $field)
    {
        $typeMappings = $this->getRegisteredTypeMappings();

        if ($metadata->hasField($field)) {
            $doctrineType = $metadata->getTypeOfField($field);
            if (!isset($typeMappings[$doctrineType])) {
                return null;
            }

            return $this->formatResult(
                $this->getLabel($metadata->getName(), $field),
                $typeMappings[$doctrineType]['type'],
                $typeMappings[$doctrineType]['options']
            );
        } elseif ($this->entityConfigProvider->hasConfig($metadata->getName(), $field)) {
            $entityConfig = $this->entityConfigProvider->getConfig($metadata->getName(), $field);
            $fieldType = $entityConfig->getId()->getFieldType();
            if (!$metadata->hasAssociation($field)) {
                return $this->formatResult(
                    $entityConfig->get('label'),
                    $typeMappings[$fieldType]['type'],
                    $typeMappings[$fieldType]['options']
                );
            }
        }

        return false;
    }

    /**
     * @param string $class
     * @param string $field
     * @param bool $multiple
     * @return string|null
     */
    protected function getLabel($class, $field, $multiple = false)
    {
        if (!$this->entityConfigProvider->hasConfig($class, $field)) {
            return null;
        }

        $entityConfig = $this->entityConfigProvider->getConfig($class, $field);
        $labelOption = $multiple ? 'plural_label' : 'label';

        return $entityConfig->get($labelOption);
    }

    /**
     * @param string $label
     * @param string $type
     * @param array $options
     * @return array
     */
    protected function formatResult($label, $type, array $options = [])
    {
        return [
            'label' => $label,
            'type' => $type,
            'options' => $options
        ];
    }

    /**
     * @param string $class
     * @return ClassMetadata
     * @throws AttributeException
     */
    protected function getMetadataForClass($class)
    {
        $entityManager = $this->managerRegistry->getManagerForClass($class);
        if (!$entityManager) {
            throw new AttributeException(sprintf('Can\'t get entity manager for class %s', $class));
        }

        return $entityManager->getClassMetadata($class);
    }

    /**
     * @return array
     */
    private function getRegisteredTypeMappings()
    {
        if ($this->doctrineTypeMappingProvider !== null) {
            return array_merge(
                $this->doctrineTypeMapping,
                $this->doctrineTypeMappingProvider->getDoctrineTypeMappings()
            );
        }

        return $this->doctrineTypeMapping;
    }
}
