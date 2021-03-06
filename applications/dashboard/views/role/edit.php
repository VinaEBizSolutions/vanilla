<?php if (!defined('APPLICATION')) exit(); ?>
<h1><?php echo $this->title(); ?></h1>
<?php
echo $this->Form->open();
echo $this->Form->errors();
?>
<ul>
    <li>
        <?php
        echo $this->Form->label('Role Name', 'Name');
        echo $this->Form->textBox('Name');
        ?>
    </li>
    <li>
        <?php
        echo $this->Form->label('Description', 'Description');
        echo $this->Form->textBox('Description', ['MultiLine' => true]);
        ?>
    </li>
    <li>
        <?php
        echo $this->Form->label('Default Type', 'Type');
        echo '<div class="Info2">'.t('Select the default type for this role, if any.').'</div>';
        echo $this->Form->dropDown('Type', $this->data('_Types'), ['IncludeNull' => true]);
        ?>
    </li>
    <li>
        <?php
        echo $this->Form->checkBox('PersonalInfo', t('RolePersonalInfo', "This role is personal info. Only users with permission to view personal info will see it."), ['value' => '1']);
        ?>
    </li>
    <?php
    $this->fireEvent('BeforeRolePermissions');

    echo $this->Form->simple(
        $this->data('_ExtendedFields', []),
        ['Wrap' => ['', '']]
    );

    if (count($this->PermissionData) > 0) {
        if ($this->Role && $this->Role->CanSession != '1') {
            ?>
            <li>
                <p class="Warning"><?php echo t('Heads Up! This is a special role that does not allow active sessions. For this reason, the permission options have been limited to "view" permissions.'); ?></p>
            </li>
        <?php
        }
        ?>
        <li class="RolePermissions">
            <?php
            echo '<strong>'.t('Check all permissions that apply to this role:').'</strong>';
            echo $this->Form->checkBoxGridGroups($this->PermissionData, 'Permission');
            ?>
        </li>
    <?php
    }
    ?>
</ul>
<?php echo $this->Form->close('Save'); ?>
