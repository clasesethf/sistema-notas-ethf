<?php
/**
 * footer.php - Plantilla de pie de página
 * Sistema de Gestión de Calificaciones - Escuela Técnica Henry Ford
 */

// Limpiar y enviar el buffer de salida si está activo
if (ob_get_length()) {
    ob_end_flush();
}
?>
                <!-- Cierre del contenido principal -->
            </main>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="footer mt-auto py-1 bg-light">
        <div class="container text-center">
            <span class="text-muted">&copy; <?= date('Y') ?> Escuela Técnica Henry Ford - Sistema de Gestión de Calificaciones</span>
        </div>
    </footer>

    <!-- JavaScript para funcionalidades específicas -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializar tooltips de Bootstrap
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Auto-cerrar alertas después de 5 segundos
            setTimeout(function() {
                var alerts = document.querySelectorAll('.alert-dismissible');
                alerts.forEach(function(alert) {
                    var bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
            
            // Manejar campos de calificaciones
            var calificacionInputs = document.querySelectorAll('input[type="number"]');
            calificacionInputs.forEach(function(input) {
                input.addEventListener('change', function() {
                    var value = parseInt(this.value);
                    if (isNaN(value)) {
                        this.value = '';
                    } else if (value < 1) {
                        this.value = 1;
                    } else if (value > 10) {
                        this.value = 10;
                    }
                });
            });
            
            // Confirmar eliminación
            var deleteButtons = document.querySelectorAll('.btn-delete');
            deleteButtons.forEach(function(button) {
                button.addEventListener('click', function(e) {
                    if (!confirm('¿Está seguro de que desea eliminar este registro? Esta acción no se puede deshacer.')) {
                        e.preventDefault();
                    }
                });
            });
        });
        
        // Función para imprimir boletín
        function imprimirBoletin() {
            window.print();
        }
        
        // Función para calcular calificación final
        function calcularCalificacionFinal(estudianteId) {
            var calificacion1c = parseFloat(document.getElementById('calificacion_1c_' + estudianteId).value) || 0;
            var calificacion2c = parseFloat(document.getElementById('calificacion_2c_' + estudianteId).value) || 0;
            var intensificacion1c = parseFloat(document.getElementById('intensificacion_1c_' + estudianteId).value) || 0;
            
            var calificacionFinal = document.getElementById('calificacion_final_' + estudianteId);
            
            // Si tiene intensificación del 1er cuatrimestre, se reemplaza la calificación del 1er cuatrimestre
            var promedio = 0;
            if (intensificacion1c > 0) {
                promedio = (intensificacion1c + calificacion2c) / 2;
            } else {
                promedio = (calificacion1c + calificacion2c) / 2;
            }
            
            // Redondear al entero más cercano
            promedio = Math.round(promedio);
            
            // Verificar que sea un número entre 1 y 10
            if (promedio >= 1 && promedio <= 10) {
                calificacionFinal.value = promedio;
            }
        }
    </script>
</body>
</html>