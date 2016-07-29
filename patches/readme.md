# How to apply the patch (only before 4.7)

1. Find the correct file for your CiviCRM version (in the same folder as this readme file)
2. Navigate to your CiviCRM code (e.g. ``/var/www/sites/all/modules/civicrm``)
3. Create a backup of the files you're about to change: ``CRM/Batch/BAO/Batch.php``, ``CRM/Financial/Form/Export.php``, ``templates/CRM/Financial/Form/Export.tpl``, ``templates/CRM/Financial/Form/Search.tpl``
4. Apply with git: ``> git apply sageexport-4.6.x.patch``
5. Or: apply with patch: ``patch -p1 < sageexport-4.6.x.patch``
