LDActiveRecordConditionBehavior generates fully quoted, escaped, and parameter bound CDbCriterias based on a CActiveRecord's attribute values
============================

Attribute values can be scalar values or arrays. If the latter a correctly parenthesized IN condition will be generated.