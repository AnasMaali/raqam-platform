<?php

return [
    'column_manager' => [
        'position' => 'المكان :position من :count.',
        'position_in_group' => 'المكان :position من :count في :group.',
        'reordered' => 'نُقل :column إلى المكان :position من :count.',
        'reordered_deferred' => 'نُقل :column إلى المكان :position من :count. طبّق الأعمدة لحفظ التغييرات.',
        'reordered_in_group' => 'نُقل :column إلى المكان :position من :count في :group.',
        'reordered_in_group_deferred' => 'نُقل :column إلى المكان :position من :count في :group. طبّق الأعمدة لحفظ التغييرات.',
        'actions' => [
            'move_up' => ['label' => 'نقل :column للأعلى'],
            'move_down' => ['label' => 'نقل :column للأسفل'],
        ],
    ],
    'columns' => [
        'editable' => [
            'saving' => 'جارٍ حفظ التغيير...',
            'saved' => 'تم حفظ التغيير.',
            'failure' => 'تعذّر التأكد من الحفظ. حدّث الصفحة قبل المحاولة مرة أخرى.',
            'unavailable' => 'لم يعد بالإمكان تعديل هذا العنصر.',
        ],
    ],
    'actions' => [
        'move_record_up' => ['label' => 'نقل :label للأعلى'],
        'move_record_down' => ['label' => 'نقل :label للأسفل'],
    ],
    'reordering' => [
        'position' => ':label، المكان :position من :count.',
        'moved' => 'نُقل :label إلى المكان :position من :count.',
    ],
];
