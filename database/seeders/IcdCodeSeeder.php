<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** A starter subset of common ICD-10 codes. Import the full WHO list for production. */
class IcdCodeSeeder extends Seeder
{
    public function run(): void
    {
        $codes = [
            ['A09', 'Infectious gastroenteritis and colitis, unspecified', 'Infectious'],
            ['A01.0', 'Typhoid fever', 'Infectious'],
            ['A90', 'Dengue fever [classical dengue]', 'Infectious'],
            ['A15.0', 'Tuberculosis of lung', 'Infectious'],
            ['B50.9', 'Plasmodium falciparum malaria, unspecified', 'Infectious'],
            ['B34.9', 'Viral infection, unspecified', 'Infectious'],
            ['U07.1', 'COVID-19, virus identified', 'Special'],
            ['C50.9', 'Malignant neoplasm of breast, unspecified', 'Neoplasms'],
            ['D50.9', 'Iron deficiency anaemia, unspecified', 'Blood'],
            ['E03.9', 'Hypothyroidism, unspecified', 'Endocrine'],
            ['E05.9', 'Thyrotoxicosis, unspecified', 'Endocrine'],
            ['E11.9', 'Type 2 diabetes mellitus without complications', 'Endocrine'],
            ['E10.9', 'Type 1 diabetes mellitus without complications', 'Endocrine'],
            ['E78.5', 'Hyperlipidaemia, unspecified', 'Endocrine'],
            ['E66.9', 'Obesity, unspecified', 'Endocrine'],
            ['F32.9', 'Depressive episode, unspecified', 'Mental'],
            ['F41.1', 'Generalized anxiety disorder', 'Mental'],
            ['G40.9', 'Epilepsy, unspecified', 'Nervous'],
            ['G43.9', 'Migraine, unspecified', 'Nervous'],
            ['H10.9', 'Conjunctivitis, unspecified', 'Eye'],
            ['H66.9', 'Otitis media, unspecified', 'Ear'],
            ['I10', 'Essential (primary) hypertension', 'Circulatory'],
            ['I20.9', 'Angina pectoris, unspecified', 'Circulatory'],
            ['I21.9', 'Acute myocardial infarction, unspecified', 'Circulatory'],
            ['I25.1', 'Atherosclerotic heart disease', 'Circulatory'],
            ['I50.9', 'Heart failure, unspecified', 'Circulatory'],
            ['I63.9', 'Cerebral infarction, unspecified', 'Circulatory'],
            ['J00', 'Acute nasopharyngitis [common cold]', 'Respiratory'],
            ['J02.9', 'Acute pharyngitis, unspecified', 'Respiratory'],
            ['J06.9', 'Acute upper respiratory infection, unspecified', 'Respiratory'],
            ['J18.9', 'Pneumonia, unspecified organism', 'Respiratory'],
            ['J20.9', 'Acute bronchitis, unspecified', 'Respiratory'],
            ['J44.9', 'Chronic obstructive pulmonary disease, unspecified', 'Respiratory'],
            ['J45.9', 'Asthma, unspecified', 'Respiratory'],
            ['K21.9', 'Gastro-oesophageal reflux disease without oesophagitis', 'Digestive'],
            ['K29.7', 'Gastritis, unspecified', 'Digestive'],
            ['K35.8', 'Acute appendicitis, other and unspecified', 'Digestive'],
            ['K40.9', 'Inguinal hernia without obstruction or gangrene', 'Digestive'],
            ['K80.2', 'Calculus of gallbladder without cholecystitis', 'Digestive'],
            ['K59.0', 'Constipation', 'Digestive'],
            ['L20.9', 'Atopic dermatitis, unspecified', 'Skin'],
            ['L03.9', 'Cellulitis, unspecified', 'Skin'],
            ['M54.5', 'Low back pain', 'Musculoskeletal'],
            ['M17.9', 'Gonarthrosis (knee osteoarthritis), unspecified', 'Musculoskeletal'],
            ['M79.1', 'Myalgia', 'Musculoskeletal'],
            ['N18.9', 'Chronic kidney disease, unspecified', 'Genitourinary'],
            ['N20.0', 'Calculus of kidney', 'Genitourinary'],
            ['N39.0', 'Urinary tract infection, site not specified', 'Genitourinary'],
            ['O80', 'Single spontaneous delivery', 'Pregnancy'],
            ['O24.4', 'Diabetes mellitus arising in pregnancy', 'Pregnancy'],
            ['R05', 'Cough', 'Symptoms'],
            ['R07.4', 'Chest pain, unspecified', 'Symptoms'],
            ['R10.4', 'Other and unspecified abdominal pain', 'Symptoms'],
            ['R50.9', 'Fever, unspecified', 'Symptoms'],
            ['R51', 'Headache', 'Symptoms'],
            ['S06.0', 'Concussion', 'Injury'],
            ['S52.5', 'Fracture of lower end of radius', 'Injury'],
            ['S72.0', 'Fracture of neck of femur', 'Injury'],
            ['T78.4', 'Allergy, unspecified', 'Injury'],
            ['Z00.0', 'General adult medical examination', 'Factors'],
            ['Z23', 'Need for immunization', 'Factors'],
            ['Z34.9', 'Supervision of normal pregnancy, unspecified', 'Factors'],
        ];

        foreach ($codes as [$code, $description, $chapter]) {
            DB::table('icd_codes')->updateOrInsert(['code' => $code], ['description' => $description, 'chapter' => $chapter]);
        }
    }
}
